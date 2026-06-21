<?php

use App\Enums\UserRole;
use App\Livewire\AiCopilot;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\AiSetting;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

/** Switch AI on in the DB-driven settings (all roles allowed). */
function enableAiSettings(): void
{
    AiSetting::current()->update([
        'enabled' => true,
        'allowed_roles' => collect(UserRole::cases())->map->value->all(),
    ]);
}

test('copilot renders nothing when no OpenAI key is configured', function () {
    config(['openai.api_key' => null]);
    $this->actingAs(User::factory()->create());

    Livewire::test(AiCopilot::class)
        ->assertOk()
        ->assertDontSee('HR Copilot');
});

test('copilot answers using the OpenAI response when enabled', function () {
    config(['openai.api_key' => 'test-key']);
    enableAiSettings();
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'FAKECOPILOTREPLY']]]]),
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    Livewire::test(AiCopilot::class)
        ->assertSee('HR Copilot')
        ->set('input', 'How many employees are on probation?')
        ->call('send')
        ->assertSee('FAKECOPILOTREPLY');
});

test('attendance AI insights are gated by the OpenAI key', function () {
    $user = User::factory()->create();
    Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    config(['openai.api_key' => null]);
    Livewire::test(AttendanceTracker::class)
        ->assertSet('aiEnabled', false)
        ->assertDontSee('AI Attendance Insights');

    config(['openai.api_key' => 'test-key']);
    enableAiSettings();
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'FAKEINSIGHT']]]]),
    ]);
    Livewire::test(AttendanceTracker::class)
        ->assertSet('aiEnabled', true)
        ->assertSee('AI Attendance Insights')
        ->call('generateAiInsight')
        ->assertSet('aiInsight', 'FAKEINSIGHT');
});
