<?php

use App\Enums\UserRole;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceService;

/**
 * Regularisation approval, routed directly to HR:
 * Pending (HR Review) → Approved/Rejected.
 * HR's approval writes attendance in the same step; managers no longer
 * decide. Every action is audited.
 */
function makeRegularisation(Employee $employee): AttendanceRegularisation
{
    $date = today()->subDay()->toDateString();

    return AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'requested_check_in' => "$date 09:00:00",
        'requested_check_out' => "$date 18:00:00",
        'check_in_method' => 'id_card',
        'check_out_method' => 'id_card',
        'reason' => 'Forgot to punch out at the gate.',
        'status' => 'pending',
        'stage' => 'hr_review',
    ]);
}

test('a half-day regularisation marks the day half_day on HR approval', function () {
    $employee = Employee::factory()->create();
    $date = today()->subDay()->toDateString();

    $reg = AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'regularisation_type' => 'half_day',
        'half_day_period' => 'first',
        'reason' => 'Left after lunch for a medical appointment.',
        'status' => 'pending',
        'stage' => 'hr_review',
    ]);
    $attendance = app(AttendanceService::class)->approveRegularisation($reg, User::factory()->create(['role' => UserRole::HrAdmin])->id);

    expect($attendance)->not->toBeNull()
        ->and($attendance->status)->toBe('half_day')
        ->and($attendance->is_regularized)->toBeTrue()
        ->and($reg->fresh()->status)->toBe('approved')
        ->and($reg->fresh()->half_day_period)->toBe('first');
});

test('the request goes straight to HR, and HR approval writes attendance', function () {
    $employee = Employee::factory()->create();
    $reg = makeRegularisation($employee);
    $service = app(AttendanceService::class);

    // 1 · A manager cannot act on it — no attendance, no trail.
    $manager = lineManager();
    expect(fn () => $service->approveRegularisation($reg, $manager->id))->toThrow(DomainException::class);
    $reg->refresh();
    expect($reg->stage)->toBe('hr_review')
        ->and($reg->status)->toBe('pending')
        ->and($reg->approval_trail)->toBeEmpty()
        ->and(AttendancePunch::where('employee_id', $employee->id)->count())->toBe(0);

    // 2 · HR approves: attendance written, punches carry direction + source.
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $attendance = $service->approveRegularisation($reg, $hr->id, 'Verified with the gate log.');
    $reg->refresh();

    expect($attendance)->not->toBeNull()
        ->and($reg->status)->toBe('approved')
        ->and($reg->approval_trail)->toHaveCount(1)
        ->and($attendance->check_in->format('H:i'))->toBe('09:00')
        ->and($attendance->check_out->format('H:i'))->toBe('18:00')
        ->and($attendance->is_regularized)->toBeTrue();       // day flagged as regularized

    $punches = AttendancePunch::where('employee_id', $employee->id)->orderBy('punched_at')->get();
    expect($punches)->toHaveCount(2)
        ->and($punches->first()->direction)->toBe('in')
        ->and($punches->last()->direction)->toBe('out')
        ->and($punches->pluck('source')->unique()->all())->toBe(['regularisation']);
});

test('a super admin also approves in one step', function () {
    $employee = Employee::factory()->create();
    $reg = makeRegularisation($employee);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $attendance = app(AttendanceService::class)->approveRegularisation($reg, $admin->id);
    $reg->refresh();

    expect($attendance)->not->toBeNull()
        ->and($reg->status)->toBe('approved');
});

test('an HR rejection ends the workflow with an audit entry', function () {
    $employee = Employee::factory()->create();
    $reg = makeRegularisation($employee);

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    app(AttendanceService::class)->rejectRegularisation($reg, $hr->id, 'Gate log shows no exit.');
    $reg->refresh();

    expect($reg->status)->toBe('rejected')
        ->and($reg->approval_trail)->toHaveCount(1)
        ->and(collect($reg->approval_trail)->last()['action'])->toBe('rejected')
        ->and(AttendancePunch::where('employee_id', $employee->id)->count())->toBe(0);
});

test('an already-decided request is not re-processed', function () {
    $employee = Employee::factory()->create();
    $reg = makeRegularisation($employee);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $service = app(AttendanceService::class);

    $service->approveRegularisation($reg, $admin->id);
    $reg->refresh();
    $trailCount = count($reg->approval_trail);

    $service->approveRegularisation($reg, $admin->id);     // second click: no-op
    $reg->refresh();
    expect(count($reg->approval_trail))->toBe($trailCount);
});
