<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ExitRecord;
use App\Models\LeavePolicy;
use App\Models\LeaveYear;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Leave\Entitlement;
use App\Services\Leave\LeaveEntitlementService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UK holiday entitlement, from the employee's working pattern.
 *
 * The statutory minimum is 5.6 weeks — weeks, not days. A five-day employee
 * gets 28 days from the same rule that gives a three-day employee 16.8. Giving
 * everyone a flat 28 would over-pay part-timers and, worse, hide the rule that
 * produced the number.
 *
 * @see https://www.gov.uk/holiday-entitlement-rights
 */
function ukeCompany(): Company
{
    Company::query()->delete();

    return Company::create([
        'name' => 'Conexus Technologies',
        'country' => 'India',
        'holiday_calendar' => 'UK',
        'leave_year_start_month' => 7,
        'leave_year_start_day' => 1,
    ]);
}

function ukePolicy(array $attributes = []): LeavePolicy
{
    LeavePolicy::query()->update(['is_default' => false]);

    return LeavePolicy::create($attributes + [
        'name' => 'Test Policy '.Str::random(5),
        'statutory_weeks' => 5.60,
        'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_ADDITIONAL,
        'irregular_accrual_rate' => 0.1207,
        'is_default' => true,
        'is_active' => true,
    ]);
}

function ukeEmployee(array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create($attributes + [
        'user_id' => $user->id,
        'status' => 'active',
        'holiday_calendar' => 'UK',
        'working_pattern' => 'regular',
        'joining_date' => '2020-01-01',
    ]);
}

function ukeYear(): LeaveYear
{
    return app(LeaveYearResolver::class)->forDate(Carbon::parse('2026-09-01'));
}

function ukeEntitlement(Employee $employee): Entitlement
{
    return app(LeaveEntitlementService::class)->for($employee->fresh(['office', 'leavePolicy']), ukeYear());
}

// ── Leave year ─────────────────────────────────────────────────────────────

test('the leave year runs 1 July to 30 June', function () {
    ukeCompany();

    $year = app(LeaveYearResolver::class)->forDate(Carbon::parse('2026-09-01'));

    expect($year->starts_on->toDateString())->toBe('2026-07-01')
        ->and($year->ends_on->toDateString())->toBe('2027-06-30')
        ->and($year->label)->toBe('2026/27');
});

test('a date before the July boundary belongs to the previous leave year', function () {
    ukeCompany();

    // 3 March 2027 is still in the year that started 1 July 2026.
    $year = app(LeaveYearResolver::class)->forDate(Carbon::parse('2027-03-03'));

    expect($year->starts_on->toDateString())->toBe('2026-07-01')
        ->and($year->label)->toBe('2026/27');
});

test('the leave year boundary is exact at both ends', function () {
    ukeCompany();
    $resolver = app(LeaveYearResolver::class);

    expect($resolver->forDate(Carbon::parse('2026-06-30'))->label)->toBe('2025/26')
        ->and($resolver->forDate(Carbon::parse('2026-07-01'))->label)->toBe('2026/27')
        ->and($resolver->forDate(Carbon::parse('2027-06-30'))->label)->toBe('2026/27')
        ->and($resolver->forDate(Carbon::parse('2027-07-01'))->label)->toBe('2027/28');
});

test('the next leave year follows on from the last day', function () {
    ukeCompany();
    $resolver = app(LeaveYearResolver::class);
    $year = $resolver->forDate(Carbon::parse('2026-09-01'));

    expect($resolver->next($year)->starts_on->toDateString())->toBe('2027-07-01')
        ->and($resolver->previous($year)->starts_on->toDateString())->toBe('2025-07-01');
});

// ── Working pattern drives entitlement ─────────────────────────────────────

test('a five-day employee gets 28 days statutory', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee(['working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5]]);

    expect(ukeEntitlement($employee)->statutoryDays)->toBe(28.0);
});

test('a four-day employee gets 22.4 days statutory', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee(['working_days_per_week' => 4, 'working_days' => [1, 2, 3, 4]]);

    expect(ukeEntitlement($employee)->statutoryDays)->toBe(22.4);
});

test('a three-day employee gets 16.8 days statutory', function () {
    // 5.6 weeks x 3 days — the example from GOV.UK. A flat 28 would have
    // handed this employee nearly two weeks they are not entitled to.
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee(['working_days_per_week' => 3, 'working_days' => [1, 2, 4]]);

    expect(ukeEntitlement($employee)->statutoryDays)->toBe(16.8);
});

test('working days are counted when days-per-week is not stated', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee(['working_days_per_week' => null, 'working_days' => [1, 2, 3]]);

    expect(ukeEntitlement($employee)->statutoryDays)->toBe(16.8);
});

test('statutory leave is capped at 28 days for a six-day week', function () {
    // Nobody accrues more than 28 days of statutory leave, however many days
    // they work. A contract may still add more on top.
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee(['working_days_per_week' => 6, 'working_days' => [1, 2, 3, 4, 5, 6]]);

    expect(ukeEntitlement($employee)->statutoryDays)->toBe(28.0);
});

// ── Shift independence ─────────────────────────────────────────────────────

test('two employees on different shifts with the same pattern get the same entitlement', function () {
    ukeCompany();
    ukePolicy();

    $early = ShiftSetting::create(['name' => '10.30 AM to 7.30 PM', 'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 5]);
    $late = ShiftSetting::create(['name' => '1PM to 10PM', 'start_time' => '13:00', 'end_time' => '22:00', 'grace_minutes' => 5]);

    $a = ukeEmployee(['shift_id' => $early->id, 'working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5]]);
    $b = ukeEmployee(['shift_id' => $late->id, 'working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5]]);

    expect(ukeEntitlement($a)->totalDays())->toBe(ukeEntitlement($b)->totalDays())
        ->and(ukeEntitlement($a)->totalDays())->toBe(28.0);
});

test('two employees on the same shift with different patterns get different entitlements', function () {
    ukeCompany();
    ukePolicy();

    $shift = ShiftSetting::create(['name' => '1PM to 10PM', 'start_time' => '13:00', 'end_time' => '22:00', 'grace_minutes' => 5]);

    $fullTime = ukeEmployee(['shift_id' => $shift->id, 'working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5]]);
    $partTime = ukeEmployee(['shift_id' => $shift->id, 'working_days_per_week' => 3, 'working_days' => [1, 2, 4]]);

    expect(ukeEntitlement($fullTime)->statutoryDays)->toBe(28.0)
        ->and(ukeEntitlement($partTime)->statutoryDays)->toBe(16.8);
});

test('changing an employee shift does not change their entitlement', function () {
    ukeCompany();
    ukePolicy();

    $early = ShiftSetting::create(['name' => '10.30 AM to 7.30 PM', 'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 5]);
    $late = ShiftSetting::create(['name' => '1PM to 10PM', 'start_time' => '13:00', 'end_time' => '22:00', 'grace_minutes' => 5]);

    $employee = ukeEmployee(['shift_id' => $early->id, 'working_days_per_week' => 4, 'working_days' => [1, 2, 3, 4]]);
    $before = ukeEntitlement($employee)->totalDays();

    $employee->update(['shift_id' => $late->id]);

    expect(ukeEntitlement($employee)->totalDays())->toBe($before);
});

// ── Statutory vs contractual ───────────────────────────────────────────────

test('contractual enhancement is reported separately from statutory', function () {
    // 5.6 statutory + 1.4 contractual = 7.0 weeks = 35 days for a 5-day week,
    // but the two halves stay distinguishable rather than collapsing to "35".
    ukeCompany();
    ukePolicy(['contractual_additional_weeks' => 1.40]);

    $employee = ukeEmployee(['working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5]]);
    $entitlement = ukeEntitlement($employee);

    expect($entitlement->statutoryDays)->toBe(28.0)
        ->and($entitlement->contractualDays)->toBe(7.0)
        ->and($entitlement->totalDays())->toBe(35.0);
});

test('contractual enhancement scales with the working pattern too', function () {
    ukeCompany();
    ukePolicy(['contractual_additional_weeks' => 1.40]);

    $employee = ukeEmployee(['working_days_per_week' => 3, 'working_days' => [1, 2, 4]]);
    $entitlement = ukeEntitlement($employee);

    expect($entitlement->statutoryDays)->toBe(16.8)
        ->and($entitlement->contractualDays)->toBe(4.2);
});

// ── Bank holidays ──────────────────────────────────────────────────────────

test('bank holidays are additional to entitlement by default', function () {
    ukeCompany();
    ukePolicy(['bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_ADDITIONAL]);

    PublicHoliday::create(['date' => '2026-12-25', 'name' => 'Christmas Day', 'country' => 'UK', 'jurisdiction' => 'england-and-wales']);

    $employee = ukeEmployee(['working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5]]);
    $entitlement = ukeEntitlement($employee);

    expect($entitlement->bankHolidayDays)->toBe(0.0)
        ->and($entitlement->bookableDays())->toBe(28.0);
});

test('bank holidays come out of entitlement when the policy says so', function () {
    ukeCompany();
    ukePolicy(['bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_INCLUDED]);

    // 25 Dec 2026 is a Friday, 28 Dec a Monday — both working days here.
    PublicHoliday::create(['date' => '2026-12-25', 'name' => 'Christmas Day', 'country' => 'UK', 'jurisdiction' => 'england-and-wales']);
    PublicHoliday::create(['date' => '2026-12-28', 'name' => 'Boxing Day substitute', 'country' => 'UK', 'jurisdiction' => 'england-and-wales']);

    $employee = ukeEmployee(['working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5]]);
    $entitlement = ukeEntitlement($employee);

    expect($entitlement->bankHolidayDays)->toBe(2.0)
        ->and($entitlement->totalDays())->toBe(28.0)
        ->and($entitlement->bookableDays())->toBe(26.0);
});

test('a bank holiday on a non-working day is not deducted', function () {
    // Acas: someone who does not work Mondays does not lose a day because a
    // bank holiday fell on one. 4 May 2026 is a Monday.
    ukeCompany();
    ukePolicy(['bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_INCLUDED]);

    PublicHoliday::create(['date' => '2026-05-04', 'name' => 'Early May Bank Holiday', 'country' => 'UK', 'jurisdiction' => 'england-and-wales']);

    // Tuesday to Saturday.
    $employee = ukeEmployee(['working_days_per_week' => 5, 'working_days' => [2, 3, 4, 5, 6]]);

    expect(app(LeaveEntitlementService::class)->bankHolidaysOnWorkingDays(
        $employee->fresh(['office']),
        app(LeaveYearResolver::class)->forDate(Carbon::parse('2026-05-04')),
    ))->toBe(0.0);
});

test('the same bank holiday is deducted for someone who does work Mondays', function () {
    ukeCompany();
    ukePolicy(['bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_INCLUDED]);

    PublicHoliday::create(['date' => '2026-05-04', 'name' => 'Early May Bank Holiday', 'country' => 'UK', 'jurisdiction' => 'england-and-wales']);

    $employee = ukeEmployee(['working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5]]);

    expect(app(LeaveEntitlementService::class)->bankHolidaysOnWorkingDays(
        $employee->fresh(['office']),
        app(LeaveYearResolver::class)->forDate(Carbon::parse('2026-05-04')),
    ))->toBe(1.0);
});

test('an India-calendar employee is unaffected by UK bank holidays', function () {
    ukeCompany();
    ukePolicy(['bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_INCLUDED]);

    PublicHoliday::create(['date' => '2026-12-25', 'name' => 'Christmas Day', 'country' => 'UK', 'jurisdiction' => 'england-and-wales']);

    $employee = ukeEmployee([
        'holiday_calendar' => 'IN',
        'working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5],
    ]);

    expect(ukeEntitlement($employee)->bankHolidayDays)->toBe(0.0);
});

// ── Joiners and leavers ────────────────────────────────────────────────────

test('an employee joining on the first day of the leave year gets the full entitlement', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee([
        'joining_date' => '2026-07-01',
        'working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5],
    ]);

    $entitlement = ukeEntitlement($employee);

    expect($entitlement->proRataFactor)->toBe(1.0)
        ->and($entitlement->statutoryDays)->toBe(28.0);
});

test('a mid-year joiner is pro-rated', function () {
    ukeCompany();
    ukePolicy();

    // 1 January 2027: 181 of the 365 days in the 2026/27 year.
    $employee = ukeEmployee([
        'joining_date' => '2027-01-01',
        'working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5],
    ]);

    $entitlement = ukeEntitlement($employee);

    expect($entitlement->proRataFactor)->toBeGreaterThan(0.49)
        ->and($entitlement->proRataFactor)->toBeLessThan(0.51)
        ->and($entitlement->statutoryDays)->toBeGreaterThan(13.0)
        ->and($entitlement->statutoryDays)->toBeLessThan(15.0);
});

test('someone joining after the leave year ends accrues nothing in it', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee([
        'joining_date' => '2027-08-01',
        'working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5],
    ]);

    expect(ukeEntitlement($employee)->statutoryDays)->toBe(0.0);
});

test('a leaver is pro-rated to their last working day', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee([
        'joining_date' => '2020-01-01',
        'working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5],
    ]);

    ExitRecord::create([
        'employee_id' => $employee->id,
        'last_working_day' => '2026-12-31',
        'resignation_date' => '2026-11-30',
        'exit_type' => 'resignation',
    ]);

    $entitlement = ukeEntitlement($employee);

    expect($entitlement->proRataFactor)->toBeLessThan(0.55)
        ->and($entitlement->proRataFactor)->toBeGreaterThan(0.45)
        ->and($entitlement->statutoryDays)->toBeLessThan(28.0);
});

test('a leaver on the last day of the leave year keeps the full entitlement', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee([
        'joining_date' => '2020-01-01',
        'working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5],
    ]);

    ExitRecord::create([
        'employee_id' => $employee->id,
        'last_working_day' => '2027-06-30',
        'resignation_date' => '2027-05-30',
        'exit_type' => 'resignation',
    ]);

    expect(ukeEntitlement($employee)->proRataFactor)->toBe(1.0);
});

// ── Irregular hours ────────────────────────────────────────────────────────

test('an irregular-hours worker accrues at 12.07 percent of hours worked', function () {
    // For leave years starting on or after 1 April 2024, irregular-hours and
    // part-year workers accrue statutory leave from hours rather than days.
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee([
        'working_pattern' => 'irregular_hours',
        'contracted_hours_per_week' => 20,
        'working_days_per_week' => 4,
        'joining_date' => '2020-01-01',
    ]);

    $entitlement = ukeEntitlement($employee);

    // 20h x 52 weeks = 1040h; 12.07% = 125.5h; at 5h/day = 25.1 days, capped
    // at the four-day-week statutory maximum of 22.4.
    expect($entitlement->method)->toBe('irregular_hours')
        ->and($entitlement->statutoryDays)->toBe(22.4)
        ->and($entitlement->explanation)->toContain('12.07%');
});

test('fewer hours produce proportionally less accrual', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee([
        'working_pattern' => 'irregular_hours',
        'contracted_hours_per_week' => 8,
        'working_days_per_week' => 2,
        'joining_date' => '2020-01-01',
    ]);

    $entitlement = ukeEntitlement($employee);

    // 8h x 52 = 416h; 12.07% = 50.2h; at 4h/day = 12.55 days, under the
    // two-day-week cap of 11.2 — so the cap applies.
    expect($entitlement->statutoryDays)->toBe(11.2)
        ->and($entitlement->method)->toBe('irregular_hours');
});

test('a part-year worker uses the same accrual method', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee([
        'working_pattern' => 'part_year',
        'contracted_hours_per_week' => 37.5,
        'working_days_per_week' => 5,
        'joining_date' => '2027-01-01',
    ]);

    $entitlement = ukeEntitlement($employee);

    expect($entitlement->method)->toBe('irregular_hours')
        ->and($entitlement->proRataFactor)->toBeLessThan(0.55)
        ->and($entitlement->statutoryDays)->toBeGreaterThan(0.0);
});

// ── Reporting ──────────────────────────────────────────────────────────────

test('the entitlement can explain how it was calculated', function () {
    ukeCompany();
    ukePolicy(['contractual_additional_weeks' => 1.40]);

    $employee = ukeEmployee(['working_days_per_week' => 3, 'working_days' => [1, 2, 4]]);
    $array = ukeEntitlement($employee)->toArray();

    expect($array)->toHaveKeys([
        'statutory_days', 'contractual_days', 'total_days',
        'bank_holiday_days', 'bookable_days', 'pro_rata_factor', 'method', 'explanation',
    ])->and($array['explanation'])->toContain('5.6 weeks x 3 days/week');
});

// ── Working-pattern fallback must not masquerade as data ───────────────────

test('an entitlement built on an assumed pattern says so', function () {
    // All nine current employees have no working pattern on record, so every
    // figure the system shows for them today rests on a Mon-Fri guess. It has
    // to be visible: a number nobody knows to doubt is the dangerous kind.
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee(['working_days_per_week' => null, 'working_days' => null]);
    $entitlement = ukeEntitlement($employee);

    expect($entitlement->patternAssumed)->toBeTrue()
        ->and($entitlement->statutoryDays)->toBe(28.0)
        ->and($entitlement->explanation)->toContain('ASSUMED')
        ->and($entitlement->explanation)->toContain('Not verified employee data')
        ->and($entitlement->toArray()['pattern_assumed'])->toBeTrue();
});

test('an entitlement built on a recorded pattern is not flagged', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee(['working_days_per_week' => 5, 'working_days' => [1, 2, 3, 4, 5]]);
    $entitlement = ukeEntitlement($employee);

    expect($entitlement->patternAssumed)->toBeFalse()
        ->and($entitlement->explanation)->not->toContain('ASSUMED');
});

test('either half of the pattern counts as recorded', function () {
    ukeCompany();
    ukePolicy();

    $daysOnly = ukeEmployee(['working_days_per_week' => 4, 'working_days' => null]);
    $weekdaysOnly = ukeEmployee(['working_days_per_week' => null, 'working_days' => [1, 2, 3]]);

    expect(ukeEntitlement($daysOnly)->patternAssumed)->toBeFalse()
        ->and(ukeEntitlement($weekdaysOnly)->patternAssumed)->toBeFalse();
});

test('an irregular-hours entitlement flags an assumed pattern too', function () {
    ukeCompany();
    ukePolicy();

    $employee = ukeEmployee([
        'working_pattern' => 'irregular_hours',
        'contracted_hours_per_week' => 20,
        'working_days_per_week' => null,
        'working_days' => null,
    ]);

    expect(ukeEntitlement($employee)->patternAssumed)->toBeTrue();
});

test('the service can be asked directly whether a pattern is on record', function () {
    ukeCompany();

    $without = ukeEmployee(['working_days_per_week' => null, 'working_days' => null]);
    $with = ukeEmployee(['working_days_per_week' => 3, 'working_days' => [1, 2, 4]]);

    $service = app(LeaveEntitlementService::class);

    expect($service->hasRecordedPattern($without->fresh()))->toBeFalse()
        ->and($service->hasRecordedPattern($with->fresh()))->toBeTrue();
});
