<?php

use App\Enums\UserRole;
use App\Livewire\Settings\MailCenter;
use App\Mail\CustomBroadcastMail;
use App\Models\AiSetting;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

/** Create a user with an employee record and a known email. */
function employeeWithEmail(string $email): User
{
    return User::factory()
        ->has(Employee::factory(), 'employee')
        ->create(['email' => $email]);
}

// ── Global kill switch (MessageSending listener) ─────────────────────────────

test('the master switch is enabled by default', function () {
    expect(MailSetting::mailEnabled())->toBeTrue();
    expect(MailSetting::count())->toBe(1);
});

test('disabling the master switch cancels every outgoing email', function () {
    MailSetting::current()->update(['mail_enabled' => false]);

    Mail::raw('hello', function ($message): void {
        $message->to('someone@example.com')->subject('Probe');
        $message->getHeaders()->addTextHeader('X-Notification-Key', 'system.killswitch-test');
    });

    expect(EmailLog::count())->toBe(0);
});

test('with the master switch on, outgoing email is logged and sent', function () {
    MailSetting::current()->update(['mail_enabled' => true]);

    Mail::raw('hello', function ($message): void {
        $message->to('someone@example.com')->subject('Probe');
        $message->getHeaders()->addTextHeader('X-Notification-Key', 'system.killswitch-test');
    });

    expect(EmailLog::where('notification_key', 'system.killswitch-test')->count())->toBe(1);
});

// ── Authorization ────────────────────────────────────────────────────────────

test('an hr admin can open the mail center', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(MailCenter::class)
        ->assertOk()
        ->assertSee('Mail Center');
});

test('a regular employee cannot open the mail center', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test(MailCenter::class)
        ->assertForbidden();
});

// ── Master toggle ────────────────────────────────────────────────────────────

test('an admin can flip the master switch with one click', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))
        ->test(MailCenter::class)
        ->assertSet('mailEnabled', true)
        ->call('toggleMaster')
        ->assertSet('mailEnabled', false);

    expect(MailSetting::current()->mail_enabled)->toBeFalse();
});

// ── Recipient selection ──────────────────────────────────────────────────────

test('select all picks every employee that has an email', function () {
    employeeWithEmail('a@example.com');
    employeeWithEmail('b@example.com');
    employeeWithEmail('c@example.com');

    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(MailCenter::class)
        ->set('selectAll', true)
        ->assertCount('selected', 3);
});

// ── Broadcast send ───────────────────────────────────────────────────────────

test('a broadcast is sent to every selected recipient', function () {
    Mail::fake();

    $a = employeeWithEmail('a@example.com');
    $b = employeeWithEmail('b@example.com');

    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(MailCenter::class)
        ->set('subject', 'Team Update')
        ->set('body', 'Hello everyone.')
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->call('send');

    Mail::assertSent(CustomBroadcastMail::class, 2);
    Mail::assertSent(CustomBroadcastMail::class, fn ($mail) => $mail->hasTo('a@example.com'));
    Mail::assertSent(CustomBroadcastMail::class, fn ($mail) => $mail->hasTo('b@example.com'));
});

test('a broadcast requires a subject, body and at least one recipient', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(MailCenter::class)
        ->set('subject', '')
        ->set('body', '')
        ->set('selected', [])
        ->call('send')
        ->assertHasErrors(['subject', 'body', 'selected']);
});

test('no broadcast is sent while the master switch is off', function () {
    Mail::fake();
    MailSetting::current()->update(['mail_enabled' => false]);

    $a = employeeWithEmail('a@example.com');

    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(MailCenter::class)
        ->set('subject', 'Team Update')
        ->set('body', 'Hello everyone.')
        ->set('selected', [(string) $a->id])
        ->call('send');

    Mail::assertNothingSent();
});

// ── Draft with AI ────────────────────────────────────────────────────────────

test('draft with ai fills the subject and body from the assistant', function () {
    config(['openai.api_key' => 'test-key']);
    AiSetting::current()->update([
        'enabled' => true,
        'allowed_roles' => ['super_admin', 'hr_admin'],
    ]);
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => [
            'content' => '{"subject":"Office Closed Friday","body":"Dear team, the office will be closed on Friday."}',
        ]]]]),
    ]);

    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(MailCenter::class)
        ->set('aiPrompt', 'tell everyone the office is closed friday')
        ->call('draftWithAi')
        ->assertSet('subject', 'Office Closed Friday')
        ->assertSet('body', 'Dear team, the office will be closed on Friday.');
});
