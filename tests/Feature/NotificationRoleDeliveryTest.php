<?php

use App\Console\Commands\CheckExcessBreaks;
use App\Enums\UserRole;
use App\Livewire\Payroll\MyPayslips;
use App\Livewire\Settings\NotificationSettings;
use App\Mail\PayslipMail;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\NotificationRoleSetting;
use App\Models\NotificationSetting;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ExcessBreakNotification;
use App\Notifications\LeaveRequestNotification;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationDeliveryGate;
use App\Services\Notifications\TemplateVariableRenderer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The reported bug: "Excess Break email was sent even when its Email toggle
 * was OFF." A direct repro of that exact scenario against ExcessBreakNotification
 * could not reproduce it — the gate already worked. The real gap was
 * structural: one row governed both the employee and the manager identically,
 * so there was no way to turn email off for one without also turning it off
 * for the other. This file proves that gap is closed.
 *
 * Assertions read EmailLog rather than Mail::fake(): a MailMessage-based
 * notification (SendsMailChannel) sends through Mailer::send($view, ...) with
 * a raw array, not a Mailable instance, and MailFake only ever records actual
 * Mailable instances — Mail::assertSent()/assertNothingSent() would pass
 * vacuously here regardless of whether the gate did anything. EmailLog is
 * written by the real MessageSending/MessageSent listeners, which fire
 * whether or not Mail::fake() is active, and phpunit.xml sets MAIL_MAILER=array
 * so nothing actually leaves the box.
 */
function nrdExcessBreakAttendance(?User $manager = null): Attendance
{
    $employee = Employee::factory()->create(['manager_id' => $manager?->id]);

    return Attendance::create([
        'employee_id' => $employee->id,
        'date' => now()->toDateString(),
        'check_in' => now()->subHours(4),
    ]);
}

function nrdSettingsAdmin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    return User::factory()->create(['role' => UserRole::SuperAdmin, 'role_id' => $role->id]);
}

function nrdSentCount(string $key, string $toEmail): int
{
    return EmailLog::where('notification_key', $key)->where('to_email', $toEmail)->where('status', 'sent')->count();
}

function nrdRole(NotificationSetting $setting, string $role): NotificationRoleSetting
{
    // A model-instance update, not a bulk query-builder update: the latter
    // bypasses Eloquent's saved event, which is what flushes the settings
    // cache -- a bulk update here would leave every subsequent read stale,
    // masking whether the gate actually re-checks anything.
    return $setting->roleSettings()->where('role', $role)->firstOrFail();
}

function nrdPayslip(Employee $employee): Payslip
{
    $payroll = Payroll::create(['month' => 'June', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'draft']);

    return Payslip::create([
        'payroll_id' => $payroll->id,
        'employee_id' => $employee->id,
        'gross_salary' => 50000,
        'total_deductions' => 5000,
        'net_salary' => 45000,
        'status' => 'paid',
    ]);
}

// ── The reported bug, fixed ─────────────────────────────────────────────────

test('employee email OFF and manager email ON are independent for the same excess-break event', function () {
    app(NotificationCatalog::class)->sync();
    $manager = User::factory()->create();
    $attendance = nrdExcessBreakAttendance($manager);
    $employee = $attendance->employee;

    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    nrdRole($setting, 'employee')->update(['mail_enabled' => false]);
    nrdRole($setting, 'manager')->update(['mail_enabled' => true]);

    $notification = new ExcessBreakNotification($attendance, 90);
    $employee->user->notify($notification->forRole('employee'));
    $manager->notify($notification->forRole('manager'));

    expect(nrdSentCount(ExcessBreakNotification::class, $employee->user->email))->toBe(0)
        ->and(nrdSentCount(ExcessBreakNotification::class, $manager->email))->toBe(1);
});

test('the reverse also holds: manager OFF does not affect the employee', function () {
    app(NotificationCatalog::class)->sync();
    $manager = User::factory()->create();
    $attendance = nrdExcessBreakAttendance($manager);
    $employee = $attendance->employee;

    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    nrdRole($setting, 'employee')->update(['mail_enabled' => true]);
    nrdRole($setting, 'manager')->update(['mail_enabled' => false]);

    $notification = new ExcessBreakNotification($attendance, 90);
    $employee->user->notify($notification->forRole('employee'));
    $manager->notify($notification->forRole('manager'));

    expect(nrdSentCount(ExcessBreakNotification::class, $employee->user->email))->toBe(1)
        ->and(nrdSentCount(ExcessBreakNotification::class, $manager->email))->toBe(0);
});

test('the real command dispatches each recipient under their own role', function () {
    app(NotificationCatalog::class)->sync();
    $manager = User::factory()->create();
    $employee = Employee::factory()->create(['manager_id' => $manager->id]);
    Attendance::create([
        'employee_id' => $employee->id, 'date' => now()->toDateString(),
        'check_in' => now()->subHours(4), 'break_minutes' => 90,
    ]);

    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    nrdRole($setting, 'employee')->update(['mail_enabled' => false]);

    $this->artisan(CheckExcessBreaks::class)->assertSuccessful();

    expect(nrdSentCount(ExcessBreakNotification::class, $employee->user->email))->toBe(0)
        ->and(nrdSentCount(ExcessBreakNotification::class, $manager->email))->toBe(1);
});

test('an event with role rows still falls back cleanly for a role nobody configured', function () {
    // Catalog sync seeds employee+manager for this event; a THIRD,
    // hypothetical role with no row must fall back to the event default,
    // not error.
    app(NotificationCatalog::class)->sync();

    $decision = app(NotificationDeliveryGate::class)->mail(ExcessBreakNotification::class, 'director');

    expect($decision->allowed)->toBeTrue();
});

// ── Templates: no cross-role leakage ────────────────────────────────────────

test('an employee-role template does not apply when sending to the manager role', function () {
    app(NotificationCatalog::class)->sync();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    nrdRole($setting, 'employee')->update(['custom_subject' => 'EMPLOYEE ONLY SUBJECT']);
    nrdRole($setting, 'manager')->update(['custom_subject' => 'MANAGER ONLY SUBJECT']);

    $attendance = nrdExcessBreakAttendance();
    $notification = new ExcessBreakNotification($attendance, 90);

    $employeeMail = $notification->forRole('employee')->toMail($attendance->employee->user);
    $managerMail = $notification->forRole('manager')->toMail($attendance->employee->user);

    expect($employeeMail->subject)->toBe('EMPLOYEE ONLY SUBJECT')
        ->and($managerMail->subject)->toBe('MANAGER ONLY SUBJECT');
});

test('a role template renders that event template variables', function () {
    app(NotificationCatalog::class)->sync();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    nrdRole($setting, 'manager')->update([
        'custom_body' => '{{employee_name}} took {{break_minutes}} minutes, {{excess_minutes}} over the {{break_limit}} limit.',
    ]);

    $manager = User::factory()->create();
    $attendance = nrdExcessBreakAttendance($manager);
    $notification = (new ExcessBreakNotification($attendance, 95))->forRole('manager');

    $mail = $notification->toMail($manager);

    expect(implode(' ', $mail->introLines))->toContain($attendance->employee->user->name)
        ->and(implode(' ', $mail->introLines))->toContain('95 minutes, 35 over the 60 limit');
});

test('an unconfigured role falls back to the event-level template, not the class default', function () {
    app(NotificationCatalog::class)->sync();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    $setting->update(['custom_subject' => 'EVENT LEVEL FALLBACK']);
    // Role rows exist (seeded by sync) but have no custom_subject of their own.

    $attendance = nrdExcessBreakAttendance();
    $notification = (new ExcessBreakNotification($attendance, 90))->forRole('employee');

    expect($notification->toMail($attendance->employee->user)->subject)->toBe('EVENT LEVEL FALLBACK');
});

// ── Queue safety: re-checked at the moment of actual delivery ──────────────

test('a notification is re-checked at delivery time against whatever the setting is right now', function () {
    app(NotificationCatalog::class)->sync();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    nrdRole($setting, 'employee')->update(['mail_enabled' => true]);

    $attendance = nrdExcessBreakAttendance();
    $notification = (new ExcessBreakNotification($attendance, 90))->forRole('employee');

    // The decision made "at dispatch time" says allowed...
    expect(app(NotificationDeliveryGate::class)->mail(ExcessBreakNotification::class, 'employee')->allowed)->toBeTrue();

    // ...but by the time it actually fires (immediately after, standing in
    // for "after a queued job sat for a while"), the setting has changed.
    nrdRole($setting, 'employee')->update(['mail_enabled' => false]);

    $attendance->employee->user->notify($notification);

    expect(nrdSentCount(ExcessBreakNotification::class, $attendance->employee->user->email))->toBe(0);
});

test('MessageSending is a second, final check — it blocks even if something changed after NotificationSending already allowed it', function () {
    app(NotificationCatalog::class)->sync();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    nrdRole($setting, 'employee')->update(['mail_enabled' => true]);

    $attendance = nrdExcessBreakAttendance();
    $notification = (new ExcessBreakNotification($attendance, 90))->forRole('employee');

    // A listener ahead of the real gate that flips the setting off between
    // the two events — simulating a change mid-flight.
    Event::listen(function (NotificationSending $event): void {
        $s = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
        nrdRole($s, 'employee')->update(['mail_enabled' => false]);
    });

    $attendance->employee->user->notify($notification);

    expect(EmailLog::where('notification_key', ExcessBreakNotification::class)
        ->where('status', 'sent')->count())->toBe(0);
    expect(EmailLog::where('notification_key', ExcessBreakNotification::class)
        ->where('status', 'skipped')->where('skip_reason', 'notification_email_disabled')->count())->toBe(1);
});

// ── Recipients that don't exist don't crash the rest ────────────────────────

test('an employee with no manager does not error — only the employee is notified', function () {
    app(NotificationCatalog::class)->sync();
    $attendance = nrdExcessBreakAttendance(manager: null);
    $notification = new ExcessBreakNotification($attendance, 90);
    $employee = $attendance->employee;

    $employee->user?->notify($notification->forRole('employee'));
    if ($employee->manager_id) {
        $employee->manager?->notify($notification->forRole('manager'));
    }

    expect(nrdSentCount(ExcessBreakNotification::class, $employee->user->email))->toBe(1)
        ->and(EmailLog::where('notification_key', ExcessBreakNotification::class)->count())->toBe(1);
});

// ── Template variables: validated, not silently broken ─────────────────────

test('saving a template with an unknown variable is rejected', function () {
    app(NotificationCatalog::class)->sync();
    $admin = nrdSettingsAdmin();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();

    Livewire::actingAs($admin)->test(NotificationSettings::class)
        ->call('openEdit', $setting->id)
        ->set('custom_body', 'Hello {{not_a_real_variable}}')
        ->call('saveEdit')
        ->assertHasErrors('custom_body');

    expect($setting->fresh()->custom_body)->toBeNull();
});

test('a known variable renders correctly and the template saves', function () {
    app(NotificationCatalog::class)->sync();
    $admin = nrdSettingsAdmin();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();

    Livewire::actingAs($admin)->test(NotificationSettings::class)
        ->call('openEdit', $setting->id)
        ->set('custom_body', 'Hello {{employee_name}}')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($setting->fresh()->custom_body)->toBe('Hello {{employee_name}}');
});

test('template renderer substitutes known tokens and leaves unknown ones untouched', function () {
    $renderer = app(TemplateVariableRenderer::class);

    expect($renderer->render('Hi {{employee_name}}, you have {{break_minutes}} min', [
        'employee_name' => 'Alex', 'break_minutes' => '90',
    ]))->toBe('Hi Alex, you have 90 min');

    expect($renderer->unknownVariables('{{employee_name}} {{bogus}}', ['employee_name']))
        ->toBe(['bogus']);
});

test('editing a template records audit history with old and new values', function () {
    app(NotificationCatalog::class)->sync();
    $admin = nrdSettingsAdmin();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();

    Livewire::actingAs($admin)->test(NotificationSettings::class)
        ->call('openEdit', $setting->id)
        ->set('custom_subject', 'New Subject')
        ->call('saveEdit');

    $log = AuditLog::where('auditable_type', NotificationSetting::class)
        ->where('auditable_id', $setting->id)
        ->where('action', 'notification.template_updated')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->new_values['custom_subject'])->toBe('New Subject')
        ->and($log->user_id)->toBe($admin->id);
});

test('editing a role-level template records its own audit history', function () {
    app(NotificationCatalog::class)->sync();
    $admin = nrdSettingsAdmin();
    $roleSetting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail()
        ->roleSettings()->where('role', 'manager')->firstOrFail();

    Livewire::actingAs($admin)->test(NotificationSettings::class)
        ->call('openEditRole', $roleSetting->id)
        ->set('custom_subject', 'Manager Subject')
        ->call('saveEdit');

    expect($roleSetting->fresh()->custom_subject)->toBe('Manager Subject');
    expect(AuditLog::where('auditable_type', NotificationRoleSetting::class)
        ->where('auditable_id', $roleSetting->id)
        ->where('action', 'notification.template_updated')->exists())->toBeTrue();
});

// ── Preview and Send Test use the exact template being edited ──────────────

test('preview renders the unsaved subject and body with sample data, not real employee data', function () {
    app(NotificationCatalog::class)->sync();
    $admin = nrdSettingsAdmin();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();

    Livewire::actingAs($admin)->test(NotificationSettings::class)
        ->call('openEdit', $setting->id)
        ->set('custom_subject', 'Preview {{employee_name}}')
        ->call('previewEdit')
        ->assertSet('showPreviewModal', true)
        ->assertSet('previewSubject', 'Preview Jordan Lee');
});

test('send test from the edit modal is gated by mail_enabled but not by Auto-Send', function () {
    app(NotificationCatalog::class)->sync();
    $admin = nrdSettingsAdmin();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    $setting->update(['mail_enabled' => true, 'is_automatic' => false]);

    Livewire::actingAs($admin)->test(NotificationSettings::class)
        ->set('testEmail', 'test-target@example.com')
        ->call('openEdit', $setting->id)
        ->call('sendTestFromEdit');

    expect(nrdSentCount(ExcessBreakNotification::class, 'test-target@example.com'))->toBe(1);
});

test('send test from the edit modal is blocked when mail_enabled is off', function () {
    app(NotificationCatalog::class)->sync();
    $admin = nrdSettingsAdmin();
    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    $setting->update(['mail_enabled' => false]);

    Livewire::actingAs($admin)->test(NotificationSettings::class)
        ->set('testEmail', 'test-target@example.com')
        ->call('openEdit', $setting->id)
        ->call('sendTestFromEdit');

    expect(nrdSentCount(ExcessBreakNotification::class, 'test-target@example.com'))->toBe(0);
});

// ── Manual sends respect mail_enabled but not Auto-Send ─────────────────────

test('the payslip resend button is gated by mail_enabled', function () {
    app(NotificationCatalog::class)->sync();
    NotificationSetting::where('key', PayslipMail::class)->firstOrFail()->update(['mail_enabled' => false]);

    $employee = Employee::factory()->create();
    $payslip = nrdPayslip($employee);

    Livewire::actingAs($employee->user)->test(MyPayslips::class)
        ->call('emailPayslip', $payslip->id);

    expect(EmailLog::where('notification_key', PayslipMail::class)->where('status', 'sent')->count())->toBe(0);
});

test('the payslip resend button ignores Auto-Send — a click is a manual send', function () {
    app(NotificationCatalog::class)->sync();
    NotificationSetting::where('key', PayslipMail::class)->firstOrFail()
        ->update(['mail_enabled' => true, 'is_automatic' => false]);

    $employee = Employee::factory()->create();
    $payslip = nrdPayslip($employee);

    Livewire::actingAs($employee->user)->test(MyPayslips::class)
        ->call('emailPayslip', $payslip->id);

    expect(EmailLog::where('notification_key', PayslipMail::class)->where('status', 'sent')->count())->toBe(1);
});

test('the automatic payslip send path DOES respect Auto-Send, unlike before', function () {
    // Before this change, is_automatic had no effect on directly-sent
    // Mailables at all — only mail_enabled was checked for them.
    app(NotificationCatalog::class)->sync();
    NotificationSetting::where('key', PayslipMail::class)->firstOrFail()
        ->update(['mail_enabled' => true, 'is_automatic' => false]);

    Mail::send([], [], function ($message): void {
        $message->to('auto-payslip@example.com')->subject('Auto payslip');
        $message->getHeaders()->addTextHeader('X-Notification-Key', PayslipMail::class);
    });

    expect(EmailLog::where('notification_key', PayslipMail::class)
        ->where('to_email', 'auto-payslip@example.com')->where('status', 'sent')->count())->toBe(0);
    expect(EmailLog::where('notification_key', PayslipMail::class)
        ->where('to_email', 'auto-payslip@example.com')
        ->where('status', 'skipped')->where('skip_reason', 'auto_send_disabled')->count())->toBe(1);
});

// ── Role tagging reaches the real dispatch paths ───────────────────────────

test('a leave request tags the manager and HR distinctly', function () {
    app(NotificationCatalog::class)->sync();

    $setting = NotificationSetting::where('key', LeaveRequestNotification::class)->firstOrFail();
    nrdRole($setting, 'manager')->update(['custom_subject' => 'MANAGER COPY']);
    nrdRole($setting, 'hr_admin')->update(['custom_subject' => 'HR COPY']);
    nrdRole($setting, 'employee')->update(['custom_subject' => 'EMPLOYEE COPY']);

    $employee = Employee::factory()->create();
    $type = LeaveType::create([
        'name' => 'Casual', 'code' => 'C'.strtoupper(Str::random(3)),
        'category' => 'annual', 'allow_paid_request' => true,
    ]);
    $request = LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(),
        'days' => 1, 'reason' => 'Test', 'status' => 'pending',
        'requested_leave_status' => 'paid',
    ]);

    $n = new LeaveRequestNotification($request);

    expect($n->forRole('manager')->toMail($employee->user)->subject)->toBe('MANAGER COPY')
        ->and($n->forRole('hr_admin')->toMail($employee->user)->subject)->toBe('HR COPY')
        ->and($n->forRole('employee')->toMail($employee->user)->subject)->toBe('EMPLOYEE COPY');
});

test('every role-tagged event resolves its own gate decision independently', function () {
    app(NotificationCatalog::class)->sync();
    $gate = app(NotificationDeliveryGate::class);
    $catalog = app(NotificationCatalog::class);

    foreach ($catalog->all() as $entry) {
        $roles = array_keys($catalog->rolesFor($entry['key']));

        if ($roles === []) {
            continue;
        }

        // Turn one role's email off; every other role for that event stays on.
        $setting = NotificationSetting::where('key', $entry['key'])->firstOrFail();
        $first = $roles[0];
        nrdRole($setting, $first)->update(['mail_enabled' => false]);

        expect($gate->mail($entry['key'], $first)->allowed)->toBeFalse();

        foreach (array_slice($roles, 1) as $other) {
            expect($gate->mail($entry['key'], $other)->allowed)->toBeTrue();
        }

        nrdRole($setting, $first)->update(['mail_enabled' => true]);
    }
});
