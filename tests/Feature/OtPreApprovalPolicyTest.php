<?php

use App\Enums\UserRole;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\OtRequestNotification;
use App\Services\AttendanceService;
use App\Services\NexflowApiService;
use App\Services\OvertimeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * D4 (8 Oct 2026): normal overtime needs the manager's pre-approval — Nexflow
 * hours never become approved, payable OT on their own. The one documented
 * exception (R9) is tested separately: HR approving a regularisation that
 * pushes the day over the threshold files and approves that OT.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-08 10:00:00'));
    $this->shift = ShiftSetting::create([
        'name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9,
    ]);
    $this->manager = User::factory()->create(['role' => UserRole::Manager]);
});

function otpEmployee(string $source = 'nexflow'): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee, 'email' => fake()->unique()->safeEmail()])->id,
        'status' => 'active', 'shift_id' => test()->shift->id, 'manager_id' => test()->manager->id,
        'ot_tracking_source' => $source, 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

test('Nexflow-detected overtime for a Nexflow-only employee is a pending request for the manager', function () {
    $employee = otpEmployee('nexflow');
    $this->mock(NexflowApiService::class, fn ($mock) => $mock->shouldReceive('getClockSummary')->andReturn([
        'daily_breakdown' => [['date' => '2026-10-07', 'ot_hours' => 2, 'net_work_hours' => 11, 'ref' => 'clock:1']],
    ]));

    $this->artisan('hrms:sync-nexflow-ot', ['--date' => '2026-10-07'])
        ->expectsOutputToContain('1 pending manager approval')
        ->assertSuccessful();

    $request = OtRequest::where('employee_id', $employee->id)->firstOrFail();

    expect($request->status)->toBe('pending')
        ->and($request->reviewer_id)->toBeNull()
        ->and(OvertimeRecord::where('ot_request_id', $request->id)->exists())->toBeFalse();

    Notification::assertSentTo($this->manager, OtRequestNotification::class);
});

test('the manager approving it is what makes it payable', function () {
    $employee = otpEmployee('nexflow');
    $this->mock(NexflowApiService::class, fn ($mock) => $mock->shouldReceive('getClockSummary')->andReturn([
        'daily_breakdown' => [['date' => '2026-10-07', 'ot_hours' => 2, 'net_work_hours' => 11, 'ref' => 'clock:2']],
    ]));
    $this->artisan('hrms:sync-nexflow-ot', ['--date' => '2026-10-07'])->assertSuccessful();
    $request = OtRequest::where('employee_id', $employee->id)->firstOrFail();

    app(OvertimeService::class)->approve($request, $this->manager->id, 'OK');

    expect($request->fresh()->status)->toBe('approved')
        ->and($request->fresh()->reviewer_id)->toBe($this->manager->id)
        ->and(OvertimeRecord::where('ot_request_id', $request->id)->exists())->toBeTrue();
});

test('R9 exception, tested on its own: HR approving a regularisation over the threshold files and approves that OT', function () {
    $employee = otpEmployee('biometric');
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $regularisation = AttendanceRegularisation::create([
        'employee_id' => $employee->id, 'work_date' => '2026-10-07', 'regularisation_type' => 'punch',
        'requested_check_in' => '2026-10-07 09:00:00', 'requested_check_out' => '2026-10-07 20:00:00',
        'reason' => 'Release night — forgot both punches', 'status' => 'pending', 'stage' => 'hr_review',
    ]);

    app(AttendanceService::class)->approveRegularisation($regularisation, $hr->id);

    $ot = OtRequest::where('employee_id', $employee->id)->where('source', 'regularisation')->firstOrFail();

    expect($ot->status)->toBe('approved')
        ->and($ot->reviewer_id)->toBe($hr->id)
        ->and(OvertimeRecord::where('ot_request_id', $ot->id)->exists())->toBeTrue()
        ->and(OtRequest::where('employee_id', $employee->id)->where('source', 'nexflow')->exists())->toBeFalse();
});
