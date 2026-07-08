<?php

use App\Enums\UserRole;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceService;

/**
 * Multi-stage regularisation approval:
 * Pending → Manager Review → HR Review → Admin Approval → Approved/Rejected.
 * Attendance is only written at FINAL approval; every action is audited.
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
        'stage' => 'manager_review',
    ]);
}

test('the request climbs manager → HR → admin, and only final approval writes attendance', function () {
    $employee = Employee::factory()->create();
    $reg = makeRegularisation($employee);
    $service = app(AttendanceService::class);

    // 1 · Manager clears manager_review only — no attendance yet.
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    expect($service->approveRegularisation($reg, $manager->id))->toBeNull();
    $reg->refresh();
    expect($reg->stage)->toBe('hr_review')
        ->and($reg->status)->toBe('pending')
        ->and($reg->approval_trail)->toHaveCount(1)
        ->and(AttendancePunch::where('employee_id', $employee->id)->count())->toBe(0);

    // 2 · HR clears hr_review — still pending.
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    expect($service->approveRegularisation($reg, $hr->id))->toBeNull();
    $reg->refresh();
    expect($reg->stage)->toBe('admin_approval')->and($reg->status)->toBe('pending');

    // 3 · A manager CANNOT act at admin stage — nothing changes.
    expect($service->approveRegularisation($reg, $manager->id))->toBeNull();
    $reg->refresh();
    expect($reg->stage)->toBe('admin_approval')->and($reg->approval_trail)->toHaveCount(2);

    // 4 · Admin finalises: attendance written, punches carry direction + source.
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $attendance = $service->approveRegularisation($reg, $admin->id, 'Verified with the gate log.');
    $reg->refresh();

    expect($attendance)->not->toBeNull()
        ->and($reg->status)->toBe('approved')
        ->and($reg->approval_trail)->toHaveCount(3)
        ->and($attendance->check_in->format('H:i'))->toBe('09:00')
        ->and($attendance->check_out->format('H:i'))->toBe('18:00');

    $punches = AttendancePunch::where('employee_id', $employee->id)->orderBy('punched_at')->get();
    expect($punches)->toHaveCount(2)
        ->and($punches->first()->direction)->toBe('in')
        ->and($punches->last()->direction)->toBe('out')
        ->and($punches->pluck('source')->unique()->all())->toBe(['regularisation']);
});

test('a super admin finalises in one step from manager review', function () {
    $employee = Employee::factory()->create();
    $reg = makeRegularisation($employee);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $attendance = app(AttendanceService::class)->approveRegularisation($reg, $admin->id);
    $reg->refresh();

    expect($attendance)->not->toBeNull()
        ->and($reg->status)->toBe('approved');
});

test('a rejection at any stage ends the workflow with an audit entry', function () {
    $employee = Employee::factory()->create();
    $reg = makeRegularisation($employee);
    $service = app(AttendanceService::class);

    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $service->approveRegularisation($reg, $manager->id);   // → hr_review
    $reg->refresh();

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $service->rejectRegularisation($reg, $hr->id, 'Gate log shows no exit.');
    $reg->refresh();

    expect($reg->status)->toBe('rejected')
        ->and($reg->approval_trail)->toHaveCount(2)
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
