<?php

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Attendance\HolidayResolver;
use Illuminate\Support\Carbon;

/**
 * One rule for "is this a holiday for this person?".
 *
 * Attendance filtered by country and ignored branch/department scope; leave
 * honoured scope and ignored country. A UK employee in the Mumbai office got
 * the wrong answer from both, in opposite directions.
 */
function chrEmployee(array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create($attributes + ['user_id' => $user->id, 'status' => 'active']);
}

function chrUkOffice(): Office
{
    return Office::factory()->create(['country' => 'United Kingdom']);
}

// ── Calendar ───────────────────────────────────────────────────────────────

test('an India employee is not on a UK holiday', function () {
    $employee = chrEmployee();
    PublicHoliday::create(['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'country' => 'UK']);

    expect(app(HolidayResolver::class)->isHoliday($employee, Carbon::parse('2026-08-31')))->toBeFalse();
});

test('a UK employee is on a UK holiday', function () {
    $employee = chrEmployee(['office_id' => chrUkOffice()->id]);
    PublicHoliday::create(['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'country' => 'UK']);

    expect(app(HolidayResolver::class)->isHoliday($employee, Carbon::parse('2026-08-31')))->toBeTrue();
});

test('the same date is a holiday for one calendar and a working day for the other', function () {
    $india = chrEmployee();
    $uk = chrEmployee(['office_id' => chrUkOffice()->id]);

    PublicHoliday::create(['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'country' => 'UK']);
    PublicHoliday::create(['date' => '2026-08-31', 'name' => 'Ganesh Chaturthi', 'country' => 'IN']);

    $resolver = app(HolidayResolver::class);
    $date = Carbon::parse('2026-08-31');

    expect($resolver->forEmployeeOn($india, $date)->name)->toBe('Ganesh Chaturthi')
        ->and($resolver->forEmployeeOn($uk, $date)->name)->toBe('Summer Bank Holiday');
});

// ── Scope ──────────────────────────────────────────────────────────────────

test('an office-scoped holiday does not apply to another office', function () {
    $mumbai = Office::factory()->create(['country' => 'India']);
    $pune = Office::factory()->create(['country' => 'India']);

    $employee = chrEmployee(['office_id' => $pune->id]);

    PublicHoliday::create([
        'date' => '2026-09-10', 'name' => 'Mumbai Local Holiday', 'country' => 'IN', 'office_id' => $mumbai->id,
    ]);

    expect(app(HolidayResolver::class)->isHoliday($employee, Carbon::parse('2026-09-10')))->toBeFalse();
});

test('an office-scoped holiday applies to its own office', function () {
    $mumbai = Office::factory()->create(['country' => 'India']);
    $employee = chrEmployee(['office_id' => $mumbai->id]);

    PublicHoliday::create([
        'date' => '2026-09-10', 'name' => 'Mumbai Local Holiday', 'country' => 'IN', 'office_id' => $mumbai->id,
    ]);

    expect(app(HolidayResolver::class)->isHoliday($employee, Carbon::parse('2026-09-10')))->toBeTrue();
});

test('a department-scoped holiday does not apply to another department', function () {
    $sales = Department::factory()->create();
    $engineering = Department::factory()->create();

    $employee = chrEmployee(['department_id' => $engineering->id]);

    PublicHoliday::create([
        'date' => '2026-09-11', 'name' => 'Sales Offsite', 'country' => 'IN', 'department_id' => $sales->id,
    ]);

    expect(app(HolidayResolver::class)->isHoliday($employee, Carbon::parse('2026-09-11')))->toBeFalse();
});

test('an employee-scoped holiday applies only to the listed employees', function () {
    $included = chrEmployee();
    $excluded = chrEmployee();

    PublicHoliday::create([
        'date' => '2026-09-12', 'name' => 'Personal Day', 'country' => 'IN',
        'applicable_employee_ids' => [$included->id],
    ]);

    $resolver = app(HolidayResolver::class);
    $date = Carbon::parse('2026-09-12');

    expect($resolver->isHoliday($included, $date))->toBeTrue()
        ->and($resolver->isHoliday($excluded, $date))->toBeFalse();
});

test('country and scope are both required, not either', function () {
    // The exact case neither old rule handled: a UK employee sitting in an
    // India office. Attendance said "UK calendar, ignore the office"; leave
    // said "this office, ignore the calendar".
    $indiaOffice = Office::factory()->create(['country' => 'India']);
    $ukEmployee = chrEmployee(['office_id' => $indiaOffice->id]);

    PublicHoliday::create([
        'date' => '2026-09-13', 'name' => 'India Office Holiday', 'country' => 'IN', 'office_id' => $indiaOffice->id,
    ]);

    // Office matches, calendar matches → it applies.
    expect(app(HolidayResolver::class)->isHoliday($ukEmployee, Carbon::parse('2026-09-13')))->toBeTrue();
});

// ── Active ─────────────────────────────────────────────────────────────────

test('an archived holiday applies to nobody', function () {
    $employee = chrEmployee();
    PublicHoliday::create(['date' => '2026-09-14', 'name' => 'Withdrawn', 'country' => 'IN', 'is_active' => false]);

    expect(app(HolidayResolver::class)->isHoliday($employee, Carbon::parse('2026-09-14')))->toBeFalse();
});

// ── Explain ────────────────────────────────────────────────────────────────

test('the resolver explains which calendar and scope decided the answer', function () {
    $office = Office::factory()->create(['country' => 'India']);
    $employee = chrEmployee(['office_id' => $office->id]);

    PublicHoliday::create([
        'date' => '2026-09-15', 'name' => 'Office Day', 'country' => 'IN', 'office_id' => $office->id,
    ]);

    $explained = app(HolidayResolver::class)->explain($employee, Carbon::parse('2026-09-15'));

    expect($explained['is_holiday'])->toBeTrue()
        ->and($explained['calendar'])->toBe('IN')
        ->and($explained['name'])->toBe('Office Day')
        ->and($explained['scope'])->toBe('office');
});

test('explain says why a date is not a holiday for this employee', function () {
    $employee = chrEmployee();
    PublicHoliday::create(['date' => '2026-09-16', 'name' => 'UK Only', 'country' => 'UK']);

    $explained = app(HolidayResolver::class)->explain($employee, Carbon::parse('2026-09-16'));

    expect($explained['is_holiday'])->toBeFalse()
        ->and($explained['calendar'])->toBe('IN')
        ->and($explained['scope'])->toBe('none');
});

// ── Multi-employee views ───────────────────────────────────────────────────

test('a range fetch gives each employee their own answer from one query', function () {
    $india = chrEmployee();
    $uk = chrEmployee(['office_id' => chrUkOffice()->id]);

    PublicHoliday::create(['date' => '2026-10-02', 'name' => 'Gandhi Jayanti', 'country' => 'IN']);
    PublicHoliday::create(['date' => '2026-12-26', 'name' => 'Boxing Day', 'country' => 'UK']);

    $resolver = app(HolidayResolver::class);
    $all = $resolver->holidaysInRange(Carbon::parse('2026-10-01'), Carbon::parse('2026-12-31'));

    expect($all)->toHaveCount(2)
        ->and($all->filter(fn ($h) => $resolver->appliesTo($h, $india))->pluck('name')->all())->toBe(['Gandhi Jayanti'])
        ->and($all->filter(fn ($h) => $resolver->appliesTo($h, $uk))->values()->pluck('name')->all())->toBe(['Boxing Day']);
});

// ── The legacy model API now shares the rule ───────────────────────────────

test('leave date blocking now respects the employee calendar too', function () {
    // PublicHoliday::holidayForEmployeeOn is what leave validation calls. It
    // honoured scope but not country, so a UK holiday blocked India leave.
    $employee = chrEmployee();
    PublicHoliday::create(['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'country' => 'UK']);

    expect(PublicHoliday::holidayForEmployeeOn(Carbon::parse('2026-08-31'), $employee))->toBeNull();
});

test('leave date blocking still blocks a holiday on the employee own calendar', function () {
    $employee = chrEmployee();
    PublicHoliday::create(['date' => '2026-10-02', 'name' => 'Gandhi Jayanti', 'country' => 'IN']);

    expect(PublicHoliday::holidayForEmployeeOn(Carbon::parse('2026-10-02'), $employee)?->name)
        ->toBe('Gandhi Jayanti');
});
