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
 * Regularisations are routed directly to HR (Oct 2026):
 *
 *   OLD  Manager → HR → Admin → Apply
 *   NEW  HR approves → Apply                 (one step; managers no longer decide)
 *        HR marks attendance → Apply         (the fast-path, unchanged)
 *
 * Both routes end in the same applied state through the same application
 * routine — what differs is only the audit (applied_via, trail action).
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

// ── Routed directly to HR ───────────────────────────────────────────────────

test('1 — an employee submission starts at HR review', function () {
    $reg = fpRequest(fpEmployee());

    expect($reg->status)->toBe('pending')
        ->and($reg->fresh()->stage)->toBe('hr_review');
});

test('2 — a manager can no longer reject; the request stays with HR', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $manager = lineManager();

    expect(fn () => app(AttendanceService::class)->rejectRegularisation($reg, $manager->id, 'Punch looks correct'))
        ->toThrow(DomainException::class, 'approved by HR');

    $reg->refresh();
    expect($reg->status)->toBe('pending')
        ->and($reg->approval_trail)->toBeEmpty()
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('3 — a manager can no longer approve; nothing is applied', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $manager = lineManager();

    expect(fn () => app(AttendanceService::class)->approveRegularisation($reg, $manager->id))
        ->toThrow(DomainException::class, 'approved by HR');

    $reg->refresh();
    expect($reg->stage)->toBe('hr_review')
        ->and($reg->status)->toBe('pending')
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('4 and 7 — an HR approval applies the correction in one step', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $attendance = app(AttendanceService::class)->approveRegularisation($reg, $hr->id, 'Gate log confirms');

    $reg->refresh();
    expect($attendance)->not->toBeNull()
        ->and($reg->status)->toBe('approved')
        ->and($reg->applied_via)->toBe('hr_direct')
        ->and($reg->applied_by)->toBe($hr->id)
        ->and($reg->approval_trail)->toHaveCount(1)
        ->and($attendance->check_in->format('H:i'))->toBe('09:00');
});

test('5 — an HR rejection ends the workflow', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $service = app(AttendanceService::class);

    $service->rejectRegularisation($reg, User::factory()->create(['role' => UserRole::HrAdmin])->id, 'No evidence');

    expect($reg->refresh()->status)->toBe('rejected')
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('8 — the super admin can also approve, in the same single step', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);

    $attendance = app(AttendanceService::class)->approveRegularisation($reg, User::factory()->create(['role' => UserRole::SuperAdmin])->id);

    $reg->refresh();
    expect($attendance)->not->toBeNull()
        ->and($reg->status)->toBe('approved')
        ->and($reg->applied_via)->toBe('hr_direct')
        ->and($reg->approval_trail)->toHaveCount(1);
});

// ── The fast-path ────────────────────────────────────────────────────────────

test('6 — HR fast-path applies the correction immediately', function () {
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'HR Officer']);

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
    $manager = lineManager();

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

        // Refused either way: out of the actor's reach, or not authorised to fast-path.
        expect(fn () => app(AttendanceService::class)->fastTrackRegularisation($reg, $actor->id))
            ->toThrow(Exception::class);

        expect($reg->refresh()->status)->toBe('pending');
    }
});

test('the guard is in the service, so the Livewire route cannot be used to bypass it', function () {
    // Parameter tampering reaches the same check: authorisation is not a UI
    // concern here.
    $employee = fpEmployee();
    $reg = fpRequest($employee);
    $manager = lineManager();

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

    // Route A — HR approves the employee's request.
    [$empA, $regA] = $mk();
    $attA = $service->approveRegularisation($regA, User::factory()->create(['role' => UserRole::HrAdmin])->id);

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

    expect($regA->applied_via)->toBe('hr_direct')
        ->and($regB->applied_via)->toBe('hr_fast_path');
});

test('the trail records the HR approval and the fast-path shortcut', function () {
    $service = app(AttendanceService::class);

    $regLong = fpRequest(fpEmployee());
    $service->approveRegularisation($regLong, User::factory()->create(['role' => UserRole::HrAdmin])->id);

    $regShort = fpRequest(fpEmployee());
    $service->fastTrackRegularisation($regShort, User::factory()->create(['role' => UserRole::HrAdmin])->id);

    expect(collect($regLong->refresh()->approval_trail)->pluck('action')->all())
        ->toBe(['approved'])
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
