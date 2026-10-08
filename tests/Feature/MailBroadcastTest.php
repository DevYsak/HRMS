<?php

use App\Enums\UserRole;
use App\Livewire\Settings\NotificationSettings;
use App\Mail\CustomBroadcastMail;
use App\Models\AiSetting;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\Notifications\NotificationDeliveryGate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

/** Create a user with an employee record and a known email. */
function broadcastRecipient(string $email): User
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

    // Nothing sent — but the attempt is recorded, not silently dropped.
    expect(EmailLog::where('status', 'sent')->count())->toBe(0)
        ->and(EmailLog::where('status', 'skipped')->where('skip_reason', 'outgoing_email_paused')->count())->toBe(1);
});

test('with the master switch on, outgoing email is logged and sent', function () {
    MailSetting::current()->update(['mail_enabled' => true]);

    Mail::raw('hello', function ($message): void {
        $message->to('someone@example.com')->subject('Probe');
        $message->getHeaders()->addTextHeader('X-Notification-Key', 'system.killswitch-test');
    });

    expect(EmailLog::where('notification_key', 'system.killswitch-test')->count())->toBe(1);
});

// ── Notifications & Email page ───────────────────────────────────────────────

test('an hr admin sees the master switch and compose button', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(NotificationSettings::class)
        ->assertOk()
        ->assertSee('Outgoing email is ENABLED')
        ->assertSee('Compose & Send');
});

test('a regular employee cannot open the notifications page', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test(NotificationSettings::class)
        ->assertForbidden();
});

// ── Master toggle ────────────────────────────────────────────────────────────

test('an admin can flip the master switch with one click', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))
        ->test(NotificationSettings::class)
        ->assertSet('mailEnabled', true)
        ->call('toggleMasterMail')
        ->assertSet('mailEnabled', false);

    expect(MailSetting::current()->mail_enabled)->toBeFalse();
});

// ── Recipient selection ──────────────────────────────────────────────────────

test('select all picks every employee that has an email', function () {
    broadcastRecipient('a@example.com');
    broadcastRecipient('b@example.com');
    broadcastRecipient('c@example.com');

    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(NotificationSettings::class)
        ->set('selectAllRecipients', true)
        ->assertCount('selectedRecipients', 3);
});

// ── Broadcast send ───────────────────────────────────────────────────────────

test('a broadcast is sent to every selected recipient', function () {
    Mail::fake();

    $a = broadcastRecipient('a@example.com');
    $b = broadcastRecipient('b@example.com');

    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(NotificationSettings::class)
        ->set('composeSubject', 'Team Update')
        ->set('composeBody', 'Hello everyone.')
        ->set('selectedRecipients', [(string) $a->id, (string) $b->id])
        ->call('sendBroadcast');

    Mail::assertSent(CustomBroadcastMail::class, 2);
    Mail::assertSent(CustomBroadcastMail::class, fn ($mail) => $mail->hasTo('a@example.com'));
    Mail::assertSent(CustomBroadcastMail::class, fn ($mail) => $mail->hasTo('b@example.com'));
});

test('a broadcast requires a subject, body and at least one recipient', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(NotificationSettings::class)
        ->set('composeSubject', '')
        ->set('composeBody', '')
        ->set('selectedRecipients', [])
        ->call('sendBroadcast')
        ->assertHasErrors(['composeSubject', 'composeBody', 'selectedRecipients']);
});

test('no broadcast is sent while the master switch is off', function () {
    Mail::fake();
    MailSetting::current()->update(['mail_enabled' => false]);

    $a = broadcastRecipient('a@example.com');

    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(NotificationSettings::class)
        ->set('composeSubject', 'Team Update')
        ->set('composeBody', 'Hello everyone.')
        ->set('selectedRecipients', [(string) $a->id])
        ->call('sendBroadcast');

    Mail::assertNothingSent();
});

test('the broadcast email is exactly the composed body with no forced signature', function () {
    $rendered = (new CustomBroadcastMail('Subject', 'This is the entire message body.'))->render();

    expect($rendered)->toContain('This is the entire message body.')
        ->and($rendered)->not->toContain('Thanks,'); // old hardcoded footer is gone
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
        ->test(NotificationSettings::class)
        ->set('aiPrompt', 'tell everyone the office is closed friday')
        ->call('draftWithAi')
        ->assertSet('composeSubject', 'Office Closed Friday')
        ->assertSet('composeBody', 'Dear team, the office will be closed on Friday.');
});

test('a broadcast is audited with its sender, recipients, time and result', function () {
    Mail::fake();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $a = broadcastRecipient('a@example.com');
    $b = broadcastRecipient('b@example.com');

    Livewire::actingAs($hr)
        ->test(NotificationSettings::class)
        ->set('composeSubject', 'Office closed Friday')
        ->set('composeBody', 'The office is closed on Friday.')
        ->set('selectedRecipients', [(string) $a->id, (string) $b->id])
        ->call('sendBroadcast');

    $audit = AuditLog::where('event', 'EMAIL_BROADCAST_SENT')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($hr->id)
        ->and($audit->new_values['recipient_user_ids'])->toEqualCanonicalizing([$a->id, $b->id])
        ->and($audit->new_values['sent'])->toBe(2)
        ->and($audit->new_values['failed'])->toBe(0)
        ->and($audit->new_values['sent_at'])->not->toBeNull();
});

test('a broadcast is a manual send, so the in-app-by-default rule never holds it back (D5)', function () {
    $headers = (new CustomBroadcastMail('Subject', 'Body'))->headers()->text;

    expect($headers['X-Notification-Manual'] ?? null)->toBe('1')
        ->and(app(NotificationDeliveryGate::class)->mail('custom.broadcast', null, manual: true)->allowed)->toBeTrue();
});

test('only a settings manager can send a broadcast', function () {
    Mail::fake();
    $employee = broadcastRecipient('e@example.com');

    Livewire::actingAs($employee)
        ->test(NotificationSettings::class)
        ->assertForbidden();

    Mail::assertNothingSent();
});
