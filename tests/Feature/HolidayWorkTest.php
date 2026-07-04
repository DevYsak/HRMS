<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\CommandCenter;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\HolidayWorkService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function hwHoliday(string $date, array $attrs = []): PublicHoliday
{
    return PublicHoliday::factory()->create(array_merge([
        'date' => $date, 'country' => 'IN', 'is_active' => true, 'name' => 'Diwali',
    ], $attrs));
}

test('submit is rejected when the date is not a holiday for the employee', function () {
    Notification::fake();
    $employee = Employee::factory()->create();

    expect(fn () => app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-20', 'reason' => 'Deadline work',
    ]))->toThrow(DomainException::class, 'not a company holiday');
});

test('an employee can submit a holiday-work request on a real holiday', function () {
    Notification::fake();
    $employee = Employee::factory()->create();
    hwHoliday('2026-08-15');

    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Release support', 'work_location' => 'wfh',
        'expected_hours' => 6, 'pay_type' => 'overtime',
    ]);

    expect($req->status)->toBe('pending');
    expect($req->work_location)->toBe('wfh');
    expect((float) $req->expected_hours)->toBe(6.0);
});

test('duplicate holiday-work requests for the same date are blocked', function () {
    Notification::fake();
    $employee = Employee::factory()->create();
    hwHoliday('2026-08-15');
    app(HolidayWorkService::class)->submit($employee, ['work_date' => '2026-08-15', 'reason' => 'first request']);

    expect(fn () => app(HolidayWorkService::class)->submit($employee, ['work_date' => '2026-08-15', 'reason' => 'second request']))
        ->toThrow(DomainException::class, 'already have a holiday-work request');
});

test('approving overtime pay creates a holiday-worked attendance and an OT record', function () {
    Notification::fake();
    $employee = Employee::factory()->create();
    $reviewer = User::factory()->create();
    hwHoliday('2026-08-15');
    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Prod deploy', 'expected_hours' => 8, 'pay_type' => 'overtime',
    ]);

    $attendance = app(HolidayWorkService::class)->approve($req, $reviewer->id);

    expect($attendance->status)->toBe('holiday_worked');
    expect((float) $attendance->total_hours)->toBe(8.0);
    expect($req->fresh()->status)->toBe('approved');
    expect($req->fresh()->attendance_id)->toBe($attendance->id);

    // The full holiday day materialised as an approved OT record for payroll.
    $ot = OtRequest::where('attendance_id', $attendance->id)->where('source', 'holiday')->first();
    expect($ot)->not->toBeNull();
    expect($ot->status)->toBe('approved');
    expect(OvertimeRecord::where('ot_request_id', $ot->id)->exists())->toBeTrue();
});

test('approving comp-off pay credits a comp-off leave balance instead of OT', function () {
    Notification::fake();
    $employee = Employee::factory()->create();
    $reviewer = User::factory()->create();
    hwHoliday('2026-08-15');
    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'On-call', 'pay_type' => 'comp_off',
    ]);

    $attendance = app(HolidayWorkService::class)->approve($req, $reviewer->id);

    expect($attendance->status)->toBe('holiday_worked');
    expect(OtRequest::where('attendance_id', $attendance->id)->exists())->toBeFalse();
    $balance = LeaveBalance::whereHas('leaveType', fn ($q) => $q->where('category', 'comp_off'))
        ->where('employee_id', $employee->id)->first();
    expect($balance)->not->toBeNull();
    expect((float) $balance->allocated_days)->toBeGreaterThan(0);
});

test('the command center lists and approves holiday-work requests', function () {
    Notification::fake();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create(['status' => 'active']);
    hwHoliday('2026-08-15');
    $req = app(HolidayWorkService::class)->submit($employee, ['work_date' => '2026-08-15', 'reason' => 'coverage']);

    Livewire::actingAs($hr)->test(CommandCenter::class)
        ->set('tab', 'holiday')
        ->assertViewHas('counts', fn ($c) => $c['holiday'] === 1)
        ->assertSee('Holiday Work')
        ->call('approveOne', 'holiday', $req->id);

    expect($req->fresh()->status)->toBe('approved');
    expect(Attendance::where('employee_id', $employee->id)->where('status', 'holiday_worked')->exists())->toBeTrue();
});
