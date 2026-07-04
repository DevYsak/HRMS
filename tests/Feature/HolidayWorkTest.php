<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\CommandCenter;
use App\Livewire\Holidays\HolidayPaySettings;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\HolidayPaySetting;
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

    // The full holiday day materialised as an approved OT record for payroll —
    // all 8 worked hours count as OT (a holiday has no "standard shift" to net
    // out against), not just hours beyond a normal 9h day.
    $ot = OtRequest::where('attendance_id', $attendance->id)->where('source', 'holiday')->first();
    expect($ot)->not->toBeNull();
    expect($ot->status)->toBe('approved');
    $record = OvertimeRecord::where('ot_request_id', $ot->id)->first();
    expect($record)->not->toBeNull();
    expect((float) $record->ot_hours)->toBe(8.0);
    expect((float) $record->ot_amount)->toBeGreaterThan(0);
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

// ─── Phase 4: Holiday Pay policy ────────────────────────────────────────────────

test('submit is rejected when the chosen pay type is disabled by policy', function () {
    Notification::fake();
    $employee = Employee::factory()->create();
    hwHoliday('2026-08-15');
    HolidayPaySetting::current()->update(['allowed_pay_types' => ['overtime', 'comp_off']]);

    expect(fn () => app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Deploy', 'pay_type' => 'double_pay',
    ]))->toThrow(DomainException::class, 'not an available pay type');
});

test('double pay applies the configured multiplier to the OT rate', function () {
    Notification::fake();
    $employee = Employee::factory()->create();
    $reviewer = User::factory()->create();
    hwHoliday('2026-08-15');
    HolidayPaySetting::current()->update(['double_pay_multiplier' => 2.5, 'ot_rate_per_hour' => 100]);

    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Prod', 'expected_hours' => 4, 'pay_type' => 'double_pay',
    ]);
    $attendance = app(HolidayWorkService::class)->approve($req, $reviewer->id);

    $ot = OtRequest::where('attendance_id', $attendance->id)->firstOrFail();
    $record = OvertimeRecord::where('ot_request_id', $ot->id)->firstOrFail();

    expect((float) $record->rate_per_hour)->toBe(250.0); // 100 * 2.5
    expect((float) $record->ot_amount)->toBe(1000.0);    // 4h * 250
});

test('comp off credits the configured day count from the policy', function () {
    Notification::fake();
    $employee = Employee::factory()->create();
    $reviewer = User::factory()->create();
    hwHoliday('2026-08-15');
    HolidayPaySetting::current()->update(['comp_off_days_per_holiday' => 1.5]);

    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Support', 'pay_type' => 'comp_off',
    ]);
    app(HolidayWorkService::class)->approve($req, $reviewer->id);

    $balance = LeaveBalance::whereHas('leaveType', fn ($q) => $q->where('category', 'comp_off'))
        ->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $balance->allocated_days)->toBe(1.5);
});

test('half day pay type credits the configured half-day comp-off amount', function () {
    Notification::fake();
    $employee = Employee::factory()->create();
    $reviewer = User::factory()->create();
    hwHoliday('2026-08-15');

    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Half day cover', 'pay_type' => 'half_day',
    ]);
    $attendance = app(HolidayWorkService::class)->approve($req, $reviewer->id);

    expect($attendance->status)->toBe('holiday_worked');
    expect(OtRequest::where('attendance_id', $attendance->id)->exists())->toBeFalse();
    $balance = LeaveBalance::whereHas('leaveType', fn ($q) => $q->where('category', 'comp_off'))
        ->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $balance->allocated_days)->toBe(0.5); // default half_day_comp_off_days
});

test('a regular employee cannot open the holiday pay settings page', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(HolidayPaySettings::class)
        ->assertForbidden();
});

test('HR can update the holiday pay policy', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(HolidayPaySettings::class)
        ->assertOk()
        ->assertSee('Holiday Pay Policy')
        ->set('enabledTypes', ['overtime', 'comp_off'])
        ->set('defaultPayType', 'overtime')
        ->set('doublePayMultiplier', 3)
        ->call('save')
        ->assertHasNoErrors();

    $settings = HolidayPaySetting::current();
    expect($settings->allowed_pay_types)->toBe(['overtime', 'comp_off']);
    expect((float) $settings->double_pay_multiplier)->toBe(3.0);
});
