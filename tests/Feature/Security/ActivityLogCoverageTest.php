<?php

use App\Enums\UserRole;
use App\Livewire\AuditLogViewer;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\NotificationSetting;
use App\Models\PublicHoliday;
use App\Models\SalaryCycle;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Audit\AuditService;
use App\Services\LeaveService;
use Livewire\Livewire;

/**
 * The central Activity Log records every required kind of administrative
 * and security action as a categorised event, and the viewer shows it
 * read-only to holders of View Activity Log.
 */
function alcEmployee(?User $manager = null): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'manager_id' => $manager?->id]);
}

function alcEvent(string $event): ?AuditLog
{
    return AuditLog::where('event', $event)->latest('id')->first();
}

function alcLeave(Employee $employee, string $status = 'pending'): LeaveRequest
{
    $type = LeaveType::firstOrCreate(['code' => 'ALC'], ['name' => 'Audit Leave', 'category' => 'other', 'color' => '#999999', 'allow_paid_request' => true, 'allow_unpaid_request' => true]);

    return LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->toDateString(),
        'days' => 1, 'reason' => 'Family event', 'status' => $status, 'requested_leave_status' => 'unpaid',
    ]);
}

function alcRegularisation(Employee $employee): AttendanceRegularisation
{
    $date = today()->subDays(2)->toDateString();

    return AttendanceRegularisation::create([
        'employee_id' => $employee->id, 'work_date' => $date,
        'requested_check_in' => "$date 09:00:00", 'requested_check_out' => "$date 18:00:00",
        'reason' => 'Forgot to punch', 'status' => 'pending',
    ]);
}

// ── Leave decisions ────────────────────────────────────────────────────────

test('a leave rejection is a categorised event with the reason', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $request = alcLeave(alcEmployee());

    app(LeaveService::class)->reviewRequest($request, $request->only(['leave_type_id', 'start_date', 'end_date', 'reason']) + ['is_half_day' => false], 'rejected', $hr->id, 'Team cover is short');

    $entry = alcEvent('LEAVE_REJECTED');
    expect($entry)->not->toBeNull()
        ->and($entry->category)->toBe(AuditService::LEAVE)
        ->and($entry->old_values['status'])->toBe('pending')
        ->and($entry->new_values['status'])->toBe('rejected')
        ->and($entry->reason)->toBe('Team cover is short')
        ->and($entry->user_id)->toBe($hr->id)
        ->and($entry->subject_employee_id)->toBe($request->employee_id);
});

test('a manager approval (final, D2) and a cancellation are recorded', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = alcLeave(alcEmployee($manager));

    app(LeaveService::class)->reviewRequest($request, $request->only(['leave_type_id', 'start_date', 'end_date', 'reason']) + ['is_half_day' => false], 'approved', $manager->id);
    expect(alcEvent('LEAVE_APPROVED')?->user_id)->toBe($manager->id)
        ->and(alcEvent('LEAVE_FORWARDED_TO_HR'))->toBeNull();

    app(LeaveService::class)->cancelRequest($request->fresh());
    expect(alcEvent('LEAVE_CANCELLED')?->new_values['status'])->toBe('cancelled');
});

// ── Attendance ─────────────────────────────────────────────────────────────

test('a regularisation approval records the day before and after', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $reg = alcRegularisation(alcEmployee());

    app(AttendanceService::class)->approveRegularisation($reg, $hr->id, 'Gate log confirms');

    $entry = alcEvent('ATTENDANCE_REGULARISATION_APPROVED');
    expect($entry->category)->toBe(AuditService::ATTENDANCE)
        ->and($entry->old_values['attendance'])->toBeNull()
        ->and($entry->new_values['attendance']['check_in'])->toEndWith('09:00')
        ->and($entry->reason)->toBe('Gate log confirms');
});

test('a regularisation rejection and an HR manual correction are recorded', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    app(AttendanceService::class)->rejectRegularisation(alcRegularisation(alcEmployee()), $hr->id, 'No evidence');
    expect(alcEvent('ATTENDANCE_REGULARISATION_REJECTED')?->reason)->toBe('No evidence');

    app(AttendanceService::class)->fastTrackRegularisation(alcRegularisation(alcEmployee()), $hr->id, 'Device offline');
    expect(alcEvent('ATTENDANCE_MANUALLY_CORRECTED')?->new_values['applied_via'])->toBe('hr_fast_path');
});

// ── Configuration ──────────────────────────────────────────────────────────

test('holiday changes are recorded with only the changed fields', function () {
    $holiday = PublicHoliday::create(['name' => 'Founders Day', 'date' => now()->addMonth()->toDateString(), 'country' => 'UK']);
    expect(alcEvent('PUBLIC_HOLIDAY_CREATED')?->new_values['name'])->toBe('Founders Day');

    $holiday->update(['name' => 'Founders’ Day']);
    $entry = alcEvent('PUBLIC_HOLIDAY_UPDATED');
    expect(array_keys($entry->new_values))->toBe(['name'])
        ->and($entry->old_values['name'])->toBe('Founders Day')
        ->and($entry->module)->toBe('holidays');

    $holiday->delete();
    expect(alcEvent('PUBLIC_HOLIDAY_DELETED')?->old_values['name'])->toBe('Founders’ Day');
});

test('notification and payroll settings changes are recorded', function () {
    $setting = NotificationSetting::create(['key' => 'App\\Notifications\\AuditProbe', 'label' => 'Probe', 'group' => 'Test', 'mail_enabled' => true, 'database_enabled' => true]);
    $setting->update(['mail_enabled' => false]);

    $entry = alcEvent('NOTIFICATION_SETTING_UPDATED');
    expect($entry->new_values)->toBe(['mail_enabled' => false])
        ->and($entry->module)->toBe('notifications');

    SalaryCycle::create(['name' => 'Audit Cycle', 'slug' => 'audit-cycle', 'start_day' => 1, 'end_day' => 31, 'pay_day' => 5]);
    expect(alcEvent('SALARY_CYCLE_CREATED')?->category)->toBe(AuditService::PAYROLL);
});

// ── Authentication ─────────────────────────────────────────────────────────

test('sign-in, a failed sign-in and sign-out are recorded', function () {
    $user = User::factory()->create(['email' => 'audit.login@conexus-ns.com', 'password' => 'Correct-Horse-9!x', 'email_verified_at' => now()]);

    $this->post(route('login.store'), ['email' => 'audit.login@conexus-ns.com', 'password' => 'wrong-password']);
    $failed = alcEvent('LOGIN_FAILED');
    expect($failed)->not->toBeNull()
        ->and($failed->new_values['email'])->toBe('audit.login@conexus-ns.com')
        ->and($failed->category)->toBe(AuditService::SECURITY);

    $this->post(route('login.store'), ['email' => 'audit.login@conexus-ns.com', 'password' => 'Correct-Horse-9!x']);
    expect(alcEvent('LOGIN')?->user_id)->toBe($user->id);

    $this->post(route('logout'));
    expect(alcEvent('LOGOUT')?->user_id)->toBe($user->id);
});

test('signing in no longer writes a generic profile "updated" row', function () {
    $user = User::factory()->create(['email' => 'quiet.login@conexus-ns.com', 'password' => 'Correct-Horse-9!x']);
    $generic = fn () => AuditLog::where('auditable_type', User::class)->where('auditable_id', $user->id)->where('action', 'updated')->count();
    $before = $generic();

    $this->post(route('login.store'), ['email' => 'quiet.login@conexus-ns.com', 'password' => 'Correct-Horse-9!x']);

    expect($generic())->toBe($before)
        ->and(alcEvent('LOGIN')?->user_id)->toBe($user->id);
});

test('a self-service password change and 2FA toggles are security events', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $user->update(['password' => 'Another-Pass-7!q']);
    expect(alcEvent('PASSWORD_CHANGED')?->category)->toBe(AuditService::SECURITY);

    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    expect(alcEvent('TWO_FACTOR_ENABLED'))->not->toBeNull();
});

// ── The viewer ─────────────────────────────────────────────────────────────

test('HR opens the Activity Log through View Activity Log; an employee cannot', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $this->actingAs($hr)->get(route('settings.audit-log'))->assertOk()->assertSee('Activity Log');
    $this->actingAs(User::factory()->create(['role' => UserRole::Employee]))->get(route('settings.audit-log'))->assertForbidden();
});

test('the viewer shows actor, role, module, affected employee and reason, and filters by them', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = alcEmployee();
    $this->actingAs($hr);
    app(AuditService::class)->event('LEAVE_REJECTED', AuditService::LEAVE, $employee, ['status' => 'pending'], ['status' => 'rejected'], 'Short-staffed', $employee->id);
    app(AuditService::class)->event('MODULE_SETTING_CHANGED', AuditService::SETTINGS, $employee, ['enabled' => true], ['enabled' => false]);

    Livewire::actingAs($hr)->test(AuditLogViewer::class)
        ->assertSee('Leave rejected')
        ->assertSee('Short-staffed')
        ->assertSee($employee->user->name)
        ->set('module', 'settings')
        ->assertSee('Module setting changed')
        ->assertDontSee('Short-staffed')
        ->set('module', '')
        ->set('event', 'LEAVE_REJECTED')
        ->assertSee('Short-staffed')
        // The label stays in the event picker, so count the results instead.
        ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->event === 'LEAVE_REJECTED');
});

test('the details show only the fields that changed', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $log = AuditLog::record($hr, 'updated', ['name' => 'Same', 'phone' => '111'], ['name' => 'Same', 'phone' => '222']);

    expect(AuditLogViewer::diffKeys($log))->toBe(['phone']);
});

test('the viewer offers no way to edit or delete an entry', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->actingAs($hr);
    app(AuditService::class)->event('LOGIN', AuditService::AUTHENTICATION, $hr);

    $html = Livewire::actingAs($hr)->test(AuditLogViewer::class)->html();

    expect($html)->not->toContain('wire:click="delete')
        ->not->toContain('wire:click="edit');
});
