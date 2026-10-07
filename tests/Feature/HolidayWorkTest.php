<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\CommandCenter;
use App\Livewire\Holidays\HolidayPaySettings;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\HolidayPaySetting;
use App\Models\LeaveBalance;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\HolidayWorkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/** Genuine holiday attendance: a Face IN and a final ID Card OUT, rebuilt through the canonical pipeline. */
function hwWorked(Employee $employee, string $date, string $in, string $out): ?Attendance
{
    foreach ([[$in, 'face', 'in'], [$out, 'id_card', 'out']] as [$time, $method, $direction]) {
        AttendancePunch::factory()->create([
            'employee_id' => $employee->id, 'punched_at' => "$date $time", 'punch_date' => $date,
            'method' => $method, 'direction' => $direction, 'source' => 'biometric',
        ]);
    }

    return app(AttendanceDayRebuilder::class)->rebuild($employee->fresh(), Carbon::parse($date))['attendance'];
}

function hwHoliday(string $date, array $attrs = []): PublicHoliday
{
    return PublicHoliday::factory()->create(array_merge([
        'date' => $date, 'country' => 'IN', 'is_active' => true, 'name' => 'Diwali',
    ], $attrs));
}

test('submit is rejected when the date is not a holiday for the employee', function () {
    Notification::fake();
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);

    expect(fn () => app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-20', 'reason' => 'Deadline work',
    ]))->toThrow(DomainException::class, 'not a company holiday');
});

test('an employee can submit a holiday-work request on a real holiday', function () {
    Notification::fake();
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);
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
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);
    hwHoliday('2026-08-15');
    app(HolidayWorkService::class)->submit($employee, ['work_date' => '2026-08-15', 'reason' => 'first request']);

    expect(fn () => app(HolidayWorkService::class)->submit($employee, ['work_date' => '2026-08-15', 'reason' => 'second request']))
        ->toThrow(DomainException::class, 'already have a holiday-work request');
});

test('approving overtime pay authorises the work; the OT record follows the genuine punches', function () {
    Notification::fake();
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);
    $reviewer = User::factory()->create(['role' => UserRole::HrAdmin]);
    hwHoliday('2026-08-15');
    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Prod deploy', 'expected_hours' => 8, 'pay_type' => 'overtime',
    ]);

    // Approval alone invents nothing: no attendance, no punch times, no hours, no pay.
    expect(app(HolidayWorkService::class)->approve($req, $reviewer->id))->toBeNull();
    expect($req->fresh()->status)->toBe('approved')
        ->and(Attendance::count())->toBe(0)
        ->and(AttendancePunch::count())->toBe(0)
        ->and(OtRequest::count())->toBe(0);

    // The employee really works 09:30 → 15:00: the actual 5.5h is what is paid
    // — not the 8 "expected" hours — and every worked hour is overtime.
    $attendance = hwWorked($employee, '2026-08-15', '09:30:00', '15:00:00');

    expect($attendance->status)->toBe('holiday_worked')
        ->and($attendance->check_in->format('H:i'))->toBe('09:30')
        ->and($attendance->check_out->format('H:i'))->toBe('15:00')
        ->and($req->fresh()->attendance_id)->toBe($attendance->id)
        ->and($req->fresh()->settled_at)->not->toBeNull()
        ->and((float) $req->fresh()->actual_hours)->toBe(5.5);

    $ot = OtRequest::where('attendance_id', $attendance->id)->where('source', 'holiday')->first();
    expect($ot)->not->toBeNull()->and($ot->status)->toBe('approved');
    $record = OvertimeRecord::where('ot_request_id', $ot->id)->first();
    expect($record)->not->toBeNull()
        ->and((float) $record->ot_hours)->toBe(5.5)
        ->and((float) $record->ot_amount)->toBeGreaterThan(0);
});

test('approving comp-off pay credits a comp-off leave balance instead of OT', function () {
    Notification::fake();
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);
    $reviewer = User::factory()->create(['role' => UserRole::HrAdmin]);
    hwHoliday('2026-08-15');
    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'On-call', 'pay_type' => 'comp_off',
    ]);

    app(HolidayWorkService::class)->approve($req, $reviewer->id);
    $compOff = fn () => LeaveBalance::whereHas('leaveType', fn ($q) => $q->where('category', 'comp_off'))->where('employee_id', $employee->id)->first();
    expect($compOff())->toBeNull();   // nothing earned before genuine attendance

    $attendance = hwWorked($employee, '2026-08-15', '10:00:00', '16:00:00');

    expect($attendance->status)->toBe('holiday_worked')
        ->and(OtRequest::where('attendance_id', $attendance->id)->exists())->toBeFalse()
        ->and($compOff())->not->toBeNull()
        ->and((float) $compOff()->allocated_days)->toBeGreaterThan(0);
});

test('the command center lists and approves holiday-work requests', function () {
    Notification::fake();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN', 'status' => 'active']);
    hwHoliday('2026-08-15');
    $req = app(HolidayWorkService::class)->submit($employee, ['work_date' => '2026-08-15', 'reason' => 'coverage']);

    Livewire::actingAs($hr)->test(CommandCenter::class)
        ->set('tab', 'holiday')
        ->assertViewHas('counts', fn ($c) => $c['holiday'] === 1)
        ->assertSee('Holiday Work')
        ->call('approveOne', 'holiday', $req->id);

    expect($req->fresh()->status)->toBe('approved');
    expect(Attendance::where('employee_id', $employee->id)->exists())->toBeFalse();   // authorised, nothing invented
});

// ─── Phase 4: Holiday Pay policy ────────────────────────────────────────────────

test('submit is rejected when the chosen pay type is disabled by policy', function () {
    Notification::fake();
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);
    hwHoliday('2026-08-15');
    HolidayPaySetting::current()->update(['allowed_pay_types' => ['overtime', 'comp_off']]);

    expect(fn () => app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Deploy', 'pay_type' => 'double_pay',
    ]))->toThrow(DomainException::class, 'not an available pay type');
});

test('double pay applies the configured multiplier to the OT rate', function () {
    Notification::fake();
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);
    $reviewer = User::factory()->create(['role' => UserRole::HrAdmin]);
    hwHoliday('2026-08-15');
    HolidayPaySetting::current()->update(['double_pay_multiplier' => 2.5, 'ot_rate_per_hour' => 100]);

    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Prod', 'expected_hours' => 4, 'pay_type' => 'double_pay',
    ]);
    app(HolidayWorkService::class)->approve($req, $reviewer->id);
    $attendance = hwWorked($employee, '2026-08-15', '10:00:00', '14:00:00');   // a real 4h

    $ot = OtRequest::where('attendance_id', $attendance->id)->firstOrFail();
    $record = OvertimeRecord::where('ot_request_id', $ot->id)->firstOrFail();

    expect((float) $record->rate_per_hour)->toBe(250.0); // 100 * 2.5
    expect((float) $record->ot_amount)->toBe(1000.0);    // 4h * 250
});

test('comp off credits the configured day count from the policy', function () {
    Notification::fake();
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);
    $reviewer = User::factory()->create(['role' => UserRole::HrAdmin]);
    hwHoliday('2026-08-15');
    HolidayPaySetting::current()->update(['comp_off_days_per_holiday' => 1.5]);

    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Support', 'pay_type' => 'comp_off',
    ]);
    app(HolidayWorkService::class)->approve($req, $reviewer->id);
    hwWorked($employee, '2026-08-15', '10:00:00', '16:00:00');

    $balance = LeaveBalance::whereHas('leaveType', fn ($q) => $q->where('category', 'comp_off'))
        ->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $balance->allocated_days)->toBe(1.5);
});

test('half day pay type credits the configured half-day comp-off amount', function () {
    Notification::fake();
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);
    $reviewer = User::factory()->create(['role' => UserRole::HrAdmin]);
    hwHoliday('2026-08-15');

    $req = app(HolidayWorkService::class)->submit($employee, [
        'work_date' => '2026-08-15', 'reason' => 'Half day cover', 'pay_type' => 'half_day',
    ]);
    app(HolidayWorkService::class)->approve($req, $reviewer->id);
    $attendance = hwWorked($employee, '2026-08-15', '10:00:00', '14:00:00');

    expect($attendance->status)->toBe('holiday_worked');
    expect(OtRequest::where('attendance_id', $attendance->id)->exists())->toBeFalse();
    $balance = LeaveBalance::whereHas('leaveType', fn ($q) => $q->where('category', 'comp_off'))
        ->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $balance->allocated_days)->toBe(0.5); // default half_day_comp_off_days
});

test('a regular employee cannot open the holiday pay settings page', function () {
    $employee = Employee::factory()->create(['holiday_calendar' => 'IN']);

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
