<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\AttendanceRegularisationNotification;
use App\Services\AttendanceService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * The staged chain and HR's fast-path.
 *
 *   OLD  Manager → HR → Admin → Apply
 *   NEW  Manager → HR → Admin → Apply        (unchanged, still the default)
 *        Manager → HR → Apply                (fast-path, explicitly authorised)
 *
 * The chain is intact: HR approving still only clears hr_review. Applying
 * immediately is a separate, separately authorised action, so "approve" never
 * quietly changed meaning for anyone. Both routes end in the same applied state
 * through the same application routine — what differs is only the audit.
 */
/** Shared by every employee in a test — shift codes are unique now. */
function fpShift(): ShiftSetting
{
    return ShiftSetting::firstOrCreate(
        ['code' => 'FP'],
        [
            'name' => 'FP Shift', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
            'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
        ],
    );
}

function fpEmployee(): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'shift_id' => fpShift()->id, 'manager_id' => null,
    ]);
}

function fpRequest(Employee $employee, string $in = '09:00', string $out = '18:00'): AttendanceRegularisation
{
    $date = now()->subDay()->toDateString();

    return AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'requested_check_in' => "{$date} {$in}:00",
        'requested_check_out' => "{$date} {$out}:00",
        'reason' => 'Device did not read my card',
        'status' => 'pending',
    ]);
}

// ── The staged chain, unchanged ──────────────────────────────────────────────

test('1 — an employee submission starts at manager review', function () {
    $reg = fpRequest(fpEmployee());

    expect($reg->status)->toBe('pending')
        ->and($reg->stage ?: 'manager_review')->toBe('manager_review');
});

test('2 — a manager rejection ends the workflow and writes no attendance', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $manager = User::factory()->create(['role' => UserRole::Manager]);

    app(AttendanceService::class)->rejectRegularisation($reg, $manager->id, 'Punch looks correct');

    $reg->refresh();
    expect($reg->status)->toBe('rejected')
        ->and($reg->approval_trail)->toHaveCount(1)
        ->and($reg->approval_trail[0]['action'])->toBe('rejected')
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('3 — a manager approval advances to HR without touching attendance', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $manager = User::factory()->create(['role' => UserRole::Manager]);

    expect(app(AttendanceService::class)->approveRegularisation($reg, $manager->id))->toBeNull();

    $reg->refresh();
    expect($reg->stage)->toBe('hr_review')
        ->and($reg->status)->toBe('pending')
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('4 and 7 — HR approving advances to admin, still applying nothing', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $service = app(AttendanceService::class);

    $service->approveRegularisation($reg, User::factory()->create(['role' => UserRole::Manager])->id);
    $result = $service->approveRegularisation($reg->refresh(), User::factory()->create(['role' => UserRole::HrAdmin])->id);

    expect($result)->toBeNull();
    $reg->refresh();
    expect($reg->stage)->toBe('admin_approval')
        ->and($reg->status)->toBe('pending')
        ->and($reg->applied_at)->toBeNull()
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('5 — an HR rejection ends the workflow', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $service = app(AttendanceService::class);

    $service->approveRegularisation($reg, User::factory()->create(['role' => UserRole::Manager])->id);
    $service->rejectRegularisation($reg->refresh(), User::factory()->create(['role' => UserRole::HrAdmin])->id, 'No evidence');

    expect($reg->refresh()->status)->toBe('rejected')
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('8 — the super admin finalises through the full chain', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $service = app(AttendanceService::class);

    $service->approveRegularisation($reg, User::factory()->create(['role' => UserRole::Manager])->id);
    $service->approveRegularisation($reg->refresh(), User::factory()->create(['role' => UserRole::HrAdmin])->id);
    $attendance = $service->approveRegularisation($reg->refresh(), User::factory()->create(['role' => UserRole::SuperAdmin])->id);

    $reg->refresh();
    expect($attendance)->not->toBeNull()
        ->and($reg->status)->toBe('approved')
        ->and($reg->applied_via)->toBe('admin_chain')
        ->and($reg->approval_trail)->toHaveCount(3);
});

// ── The fast-path ────────────────────────────────────────────────────────────

test('6 — HR fast-path applies the correction immediately', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'HR Officer']);

    app(AttendanceService::class)->approveRegularisation($reg, User::factory()->create(['role' => UserRole::Manager])->id);

    $attendance = app(AttendanceService::class)
        ->fastTrackRegularisation($reg->refresh(), $hr->id, 'Gate log confirms 09:00');

    $reg->refresh();
    expect($attendance)->not->toBeNull()
        ->and($reg->status)->toBe('approved')
        ->and($reg->applied_via)->toBe('hr_fast_path')
        ->and($reg->applied_by)->toBe($hr->id)
        ->and($reg->applied_at)->not->toBeNull()
        // The shortcut is named in the trail, not disguised as an approval.
        ->and(collect($reg->approval_trail)->last()['action'])->toBe('fast_tracked')
        ->and(collect($reg->approval_trail)->last()['name'])->toBe('HR Officer');
});

test('the fast-path can be used without a manager decision first', function () {
    // HR marking attendance itself never went to a manager.
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $attendance = app(AttendanceService::class)->fastTrackRegularisation($reg, $hr->id);

    expect($attendance)->not->toBeNull()
        ->and($reg->refresh()->applied_via)->toBe('hr_fast_path');
});

// ── Security ─────────────────────────────────────────────────────────────────

test('9 — a manager cannot fast-path, even though they may approve', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $manager = User::factory()->create(['role' => UserRole::Manager]);

    // Approving is theirs; applying unreviewed is not.
    expect($manager->hasPermission('approve_regularisation'))->toBeTrue()
        ->and($manager->hasPermission('manage_attendance'))->toBeFalse();

    expect(fn () => app(AttendanceService::class)->fastTrackRegularisation($reg, $manager->id))
        ->toThrow(DomainException::class);

    expect($reg->refresh()->status)->toBe('pending')
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('9b — an employee and a finance user cannot fast-path', function () {
    $employee = fpEmployee();

    foreach ([UserRole::Employee, UserRole::Finance] as $role) {
        $reg = fpRequest($employee);
        $actor = User::factory()->create(['role' => $role]);

        expect(fn () => app(AttendanceService::class)->fastTrackRegularisation($reg, $actor->id))
            ->toThrow(DomainException::class);

        expect($reg->refresh()->status)->toBe('pending');
    }
});

test('the guard is in the service, so the Livewire route cannot be used to bypass it', function () {
    // Parameter tampering reaches the same check: authorisation is not a UI
    // concern here.
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $manager = User::factory()->create(['role' => UserRole::Manager]);

    Livewire::actingAs($manager)->test(AllAttendance::class)
        ->set('markEmployeeId', $employee->id)
        ->set('markDate', now()->subDay()->toDateString())
        ->set('markCheckIn', '09:00')
        ->set('markCheckOut', '18:00')
        ->set('markReason', 'Trying to bypass review')
        ->call('submitMarkAttendance')
        ->assertForbidden();
});

// ── Audit equivalence ────────────────────────────────────────────────────────

test('10 and 11 — both routes preserve the original values and write the same correction', function () {
    $service = app(AttendanceService::class);
    $date = now()->subDay()->toDateString();

    $mk = function () use ($date) {
        $employee = fpEmployee();
        Attendance::create([
            'employee_id' => $employee->id, 'date' => $date,
            'check_in' => "{$date} 10:45:00", 'check_out' => "{$date} 18:00:00",
            'status' => 'late', 'work_mode' => 'office',
        ]);

        return [$employee, fpRequest($employee)];
    };

    // Route A — full chain.
    [$empA, $regA] = $mk();
    $service->approveRegularisation($regA, User::factory()->create(['role' => UserRole::Manager])->id);
    $service->approveRegularisation($regA->refresh(), User::factory()->create(['role' => UserRole::HrAdmin])->id);
    $attA = $service->approveRegularisation($regA->refresh(), User::factory()->create(['role' => UserRole::SuperAdmin])->id);

    // Route B — fast-path.
    [$empB, $regB] = $mk();
    $attB = $service->fastTrackRegularisation($regB, User::factory()->create(['role' => UserRole::HrAdmin])->id);

    // Same correction, same snapshot of what was there before.
    expect($attA->check_in->format('H:i'))->toBe($attB->check_in->format('H:i'))
        ->and($attA->total_hours)->toBe($attB->total_hours)
        ->and($attA->original_check_in->format('H:i'))->toBe('10:45')
        ->and($attB->original_check_in->format('H:i'))->toBe('10:45')
        ->and($attA->is_regularized)->toBeTrue()
        ->and($attB->is_regularized)->toBeTrue();

    // Both know who applied it and when; only the route differs.
    $regA->refresh();
    $regB->refresh();
    foreach ([$regA, $regB] as $reg) {
        expect($reg->status)->toBe('approved')
            ->and($reg->applied_by)->not->toBeNull()
            ->and($reg->applied_at)->not->toBeNull()
            ->and($reg->reason)->toBe('Device did not read my card');
    }

    expect($regA->applied_via)->toBe('admin_chain')
        ->and($regB->applied_via)->toBe('hr_fast_path');
});

test('the trail records every decision on the long route and the shortcut on the short one', function () {
    $service = app(AttendanceService::class);

    $regLong = fpRequest(fpEmployee());
    $service->approveRegularisation($regLong, User::factory()->create(['role' => UserRole::Manager])->id);
    $service->approveRegularisation($regLong->refresh(), User::factory()->create(['role' => UserRole::HrAdmin])->id);
    $service->approveRegularisation($regLong->refresh(), User::factory()->create(['role' => UserRole::SuperAdmin])->id);

    $regShort = fpRequest(fpEmployee());
    $service->fastTrackRegularisation($regShort, User::factory()->create(['role' => UserRole::HrAdmin])->id);

    expect(collect($regLong->refresh()->approval_trail)->pluck('action')->all())
        ->toBe(['approved', 'approved', 'approved'])
        ->and(collect($regShort->refresh()->approval_trail)->pluck('action')->all())
        ->toBe(['fast_tracked']);
});

// ── Notifications ────────────────────────────────────────────────────────────

test('12 and 13 — HR marking attendance notifies the employee whose day changed', function () {
    Notification::fake();

    $employee = fpEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->set('markEmployeeId', $employee->id)
        ->set('markDate', now()->subDay()->toDateString())
        ->set('markCheckIn', '09:00')
        ->set('markCheckOut', '18:00')
        ->set('markReason', 'Device was offline all morning')
        ->call('submitMarkAttendance');

    Notification::assertSentTo($employee->user, AttendanceRegularisationNotification::class);
});

test('an already-resolved request is not applied twice by either route', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $service = app(AttendanceService::class);

    $service->fastTrackRegularisation($reg, User::factory()->create(['role' => UserRole::HrAdmin])->id);
    $firstAppliedAt = $reg->refresh()->applied_at;

    $service->fastTrackRegularisation($reg, User::factory()->create(['role' => UserRole::HrAdmin])->id);
    $service->approveRegularisation($reg->refresh(), User::factory()->create(['role' => UserRole::SuperAdmin])->id);

    expect($reg->refresh()->applied_at->toDateTimeString())->toBe($firstAppliedAt->toDateTimeString())
        ->and($reg->approval_trail)->toHaveCount(1);
});
