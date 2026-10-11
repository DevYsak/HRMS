<?php

use App\Enums\UserRole;
use App\Livewire\AiAssistantPage;
use App\Livewire\AiCopilot;
use App\Models\AiSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\User;
use App\Services\Ai\AiConversation;
use App\Services\Ai\AiDataTools;
use App\Services\Ai\AiIntentRouter;
use App\Services\AiAssistant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;

/** The assistant on for every role, with no external model key. */
function assistantOnWithoutModel(): void
{
    config(['openai.api_key' => null, 'services.anthropic.key' => null]);

    AiSetting::current()->update([
        'enabled' => true,
        'api_key' => null,
        'provider' => 'openai',
        'allowed_roles' => collect(UserRole::cases())->map->value->all(),
    ]);
}

function employeeUser(UserRole $role = UserRole::Employee): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(['user_id' => $user->id]);

    return $user->fresh();
}

test('an employee with one pending OT request is told one, never zero', function () {
    $user = employeeUser();
    OtRequest::factory()->create(['employee_id' => $user->employee->id, 'status' => 'pending']);

    $answer = app(AiIntentRouter::class)->answer($user, 'Do I have any pending OT requests?');

    expect($answer['content'])->toContain('1 OT request')
        ->and($answer['source'])->toBe('pulse')
        ->and(collect($answer['links'])->pluck('url'))->toContain(route('overtime.my'))
        ->and(collect($answer['links'])->pluck('url'))->not->toContain(route('overtime.manage'));
});

test('how many OT requests do I have summarises my own requests by status', function () {
    $user = employeeUser();
    $employeeId = $user->employee->id;
    OtRequest::factory()->create(['employee_id' => $employeeId, 'status' => 'pending']);
    OtRequest::factory()->create(['employee_id' => $employeeId, 'status' => 'approved']);
    OtRequest::factory()->create(['employee_id' => $employeeId, 'status' => 'rejected']);

    $answer = app(AiIntentRouter::class)->answer($user, 'How many OT requests do I have?');

    expect($answer['content'])->toContain('3 OT requests in total')
        ->toContain('1 pending')
        ->toContain('1 approved')
        ->toContain('1 rejected');
});

test('an employee asking about OT pending their approval gets their own count, not a fake zero queue', function () {
    $user = employeeUser();
    OtRequest::factory()->create(['employee_id' => $user->employee->id, 'status' => 'pending']);

    $answer = app(AiIntentRouter::class)->answer($user, 'How many OT requests are pending my approval?');

    expect($answer['content'])->toContain("You don't approve OT requests")
        ->toContain('1 OT request');
});

test('a manager sees their reports OT waiting for approval and their own pending OT separately', function () {
    $report = employeeUser();
    $manager = lineManager();
    Employee::factory()->create(['user_id' => $manager->id]);
    $manager = $manager->fresh();
    $outsider = employeeUser();
    // The factory picks a random manager; this one must sit outside the line.
    $outsider->employee->update(['manager_id' => null]);

    OtRequest::factory()->create(['employee_id' => $report->employee->id, 'status' => 'pending']);
    OtRequest::factory()->create(['employee_id' => $outsider->employee->id, 'status' => 'pending']);
    OtRequest::factory()->create(['employee_id' => $manager->employee->id, 'status' => 'pending']);

    $tools = app(AiDataTools::class);

    expect($tools->approvalQueue($manager)['overtime'])->toBe(1)
        ->and($tools->myPendingRequests($manager)['overtime'])->toBe(1);

    $queueAnswer = app(AiIntentRouter::class)->answer($manager, 'How many OT requests are pending my approval?');
    expect($queueAnswer['content'])->toContain('1 OT request is waiting for your approval')
        ->toContain('Your own requests are not counted')
        ->and($queueAnswer['links'][0]['url'])->toBe(route('overtime.manage'));
});

test('HR counts the whole company queue except their own request', function () {
    $hr = employeeUser(UserRole::HrAdmin);
    $a = employeeUser();
    $b = employeeUser();

    OtRequest::factory()->create(['employee_id' => $a->employee->id, 'status' => 'pending']);
    OtRequest::factory()->create(['employee_id' => $b->employee->id, 'status' => 'pending']);
    OtRequest::factory()->create(['employee_id' => $hr->employee->id, 'status' => 'pending']);
    OtRequest::factory()->create(['employee_id' => $a->employee->id, 'status' => 'approved']);

    expect(app(AiDataTools::class)->approvalQueue($hr)['overtime'])->toBe(2);
});

test('an employee has no approval queue at all', function () {
    expect(app(AiDataTools::class)->approvalQueue(employeeUser()))->toBe([]);
});

test('the assistant page answers OT counts from Pulse data without any external model', function () {
    assistantOnWithoutModel();
    $user = employeeUser();
    OtRequest::factory()->create(['employee_id' => $user->employee->id, 'status' => 'pending']);

    $this->actingAs($user);

    Livewire::test(AiAssistantPage::class)
        ->assertSee('Answers are limited to your authorised HR data.')
        ->assertSee('Your data only')
        ->call('sendQuick', 'Do I have any pending OT requests?')
        ->assertSee('You have 1 OT request waiting for a decision.')
        ->assertSee('Open My Overtime')
        ->assertDontSee('Open Overtime Requests');

    $audit = AuditLog::where('action', 'ai.asked')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->new_values['source'])->toBe('pulse')
        ->and($audit->new_values['sent_to_external_model'])->toBeFalse();
});

test('without a model the assistant falls back to help with links the user can open', function () {
    assistantOnWithoutModel();
    $user = employeeUser();
    $this->actingAs($user);

    $answer = app(AiConversation::class)->respond($user, 'Where do I regularise a missed punch?');

    expect($answer['source'])->toBe('help')
        ->and($answer['content'])->toContain('My Work → My Attendance')
        ->and(collect($answer['links'])->pluck('url'))->toContain(route('attendance.my'))
        ->and(collect($answer['links'])->pluck('url'))->not->toContain(route('attendance.command-center'));

    $unknown = app(AiConversation::class)->respond($user, 'Tell me something interesting');
    expect($unknown['source'])->toBe('help')
        ->and($unknown['content'])->toContain("can't answer that from your Pulse data");
});

test('the external model gets only authorised aggregate context and the personal vs approval split', function () {
    config(['openai.api_key' => 'test-key']);
    AiSetting::current()->update(['enabled' => true, 'provider' => 'openai', 'allowed_roles' => ['employee']]);
    OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['content' => 'MODEL REPLY']]]])]);

    $user = employeeUser();
    $colleague = employeeUser();
    OtRequest::factory()->create(['employee_id' => $user->employee->id, 'status' => 'pending']);
    OtRequest::factory()->create(['employee_id' => $colleague->employee->id, 'status' => 'pending']);
    $this->actingAs($user);

    $answer = app(AiConversation::class)->respond($user, 'Summarise my week in one sentence');

    expect($answer['source'])->toBe('ai')->and($answer['content'])->toBe('MODEL REPLY');

    OpenAI::assertSent(Chat::class, function (string $method, array $parameters) use ($colleague, $user): bool {
        $system = $parameters['messages'][0]['content'];
        $context = json_decode(trim(str($system)->after('CONTEXT:')->toString()), true);

        return $context['me']['my_pending_requests']['overtime'] === 1
            && ! array_key_exists('waiting_for_my_approval', $context)
            && ! str_contains($system, $colleague->name)
            && ! str_contains($system, $user->name)
            && str_contains($system, 'Never invent');
    });
});

test('the copilot shares the same deterministic answer', function () {
    assistantOnWithoutModel();
    $user = employeeUser();
    OtRequest::factory()->create(['employee_id' => $user->employee->id, 'status' => 'pending']);
    $this->actingAs($user);

    Livewire::test(AiCopilot::class)
        ->set('input', 'Do I have any pending OT requests?')
        ->call('send')
        ->assertSee('You have 1 OT request waiting for a decision.');
});

test('suggested questions follow what the user can see', function () {
    $employee = employeeUser();
    $hr = employeeUser(UserRole::HrAdmin);

    $employeeGroups = app(AiConversation::class)->suggestedQuestions($employee);
    $hrGroups = app(AiConversation::class)->suggestedQuestions($hr);

    expect($employeeGroups)->toHaveKey('My requests')
        ->not->toHaveKey('Approvals')
        ->not->toHaveKey('Organisation')
        ->and($hrGroups)->toHaveKey('Approvals')
        ->toHaveKey('Organisation');
});

test('the Anthropic provider is configured from settings or env, never from code', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'CLAUDE OK']]])]);

    AiSetting::current()->update(['enabled' => true, 'provider' => 'anthropic', 'api_key' => null, 'model' => null]);
    config(['services.anthropic.key' => 'env-anthropic-key']);

    $assistant = app(AiAssistant::class);

    expect($assistant->enabled())->toBeTrue()
        ->and($assistant->ask('system', 'hello'))->toBe('CLAUDE OK');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages'
        && $request->hasHeader('x-api-key', 'env-anthropic-key')
        && $request['system'] === 'system'
        && $request['model'] === 'claude-opus-5-5');
});
