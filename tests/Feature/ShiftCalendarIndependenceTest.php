<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\HolidayResolver;

/**
 * A shift says WHEN somebody works. A holiday calendar says WHICH holidays and
 * policy apply to them. They are independent, and nothing may infer one from
 * the other.
 *
 * The original defect read the shift NAME and treated "contains UK" as meaning
 * the UK calendar. Conexus's UK Operations shift is called "1PM to 10PM", so
 * the whole UK team was placed on the Indian calendar. The same shift is also
 * worked by people on different calendars, in different offices — so no
 * property of a shift could ever answer the question correctly.
 *
 * These tests exist so the coupling cannot be reintroduced, including by
 * accident in some later refactor.
 */
function sciCompany(string $calendar = 'UK'): Company
{
    Company::query()->delete();

    return Company::create(['name' => 'Conexus Technologies', 'country' => 'India', 'holiday_calendar' => $calendar]);
}

function sciShift(string $name, string $start = '13:00', string $end = '22:00'): ShiftSetting
{
    return ShiftSetting::create(['name' => $name, 'start_time' => $start, 'end_time' => $end, 'grace_minutes' => 5]);
}

function sciEmployee(array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create($attributes + ['user_id' => $user->id, 'status' => 'active']);
}

function sciCalendarOf(Employee $employee): string
{
    // Relationships are re-read deliberately: a stale cached shift/office would
    // make these tests pass for the wrong reason.
    return app(HolidayResolver::class)->resolveCountry($employee->fresh(['office']));
}

// ── 1. Different shifts, same calendar ─────────────────────────────────────

test('1 — two employees on different shifts can share one holiday calendar', function () {
    sciCompany('UK');

    $ukOps = sciShift('1PM to 10PM', '13:00', '22:00');
    $itDay = sciShift('10.30 AM to 7.30 PM', '10:30', '19:30');
    $lateDay = sciShift('10.30 AM to 8.30 PM', '10:30', '20:30');

    $a = sciEmployee(['shift_id' => $ukOps->id, 'holiday_calendar' => 'UK']);
    $b = sciEmployee(['shift_id' => $itDay->id, 'holiday_calendar' => 'UK']);
    $c = sciEmployee(['shift_id' => $lateDay->id, 'holiday_calendar' => 'UK']);

    expect(sciCalendarOf($a))->toBe('UK')
        ->and(sciCalendarOf($b))->toBe('UK')
        ->and(sciCalendarOf($c))->toBe('UK');
});

// ── 2. Same shift, different calendars ─────────────────────────────────────

test('2 — two employees on the same shift can be on different calendars', function () {
    // The scenario the old rule could not express at all: one shift, two
    // calendars. Under shift-name inference both would have got the same
    // answer, and one of them would have been wrong.
    sciCompany('UK');

    $shared = sciShift('1PM to 10PM');

    $a = sciEmployee(['shift_id' => $shared->id, 'holiday_calendar' => 'UK']);
    $b = sciEmployee(['shift_id' => $shared->id, 'holiday_calendar' => 'IN']);

    expect(sciCalendarOf($a))->toBe('UK')
        ->and(sciCalendarOf($b))->toBe('IN')
        ->and($a->shift_id)->toBe($b->shift_id);
});

// ── 3. Changing shift does not move the calendar ───────────────────────────

test('3 — changing an employee shift leaves their holiday calendar alone', function () {
    sciCompany('UK');

    $ukOps = sciShift('1PM to 10PM', '13:00', '22:00');
    $itDay = sciShift('10.30 AM to 7.30 PM', '10:30', '19:30');

    $employee = sciEmployee(['shift_id' => $ukOps->id, 'holiday_calendar' => 'UK']);
    expect(sciCalendarOf($employee))->toBe('UK');

    $employee->update(['shift_id' => $itDay->id]);

    expect(sciCalendarOf($employee))->toBe('UK')
        ->and($employee->fresh()->shift_id)->toBe($itDay->id);
});

// ── 4. Changing calendar does not move the shift ───────────────────────────

test('4 — changing an employee holiday calendar leaves their shift alone', function () {
    sciCompany('UK');

    $shift = sciShift('1PM to 10PM');
    $employee = sciEmployee(['shift_id' => $shift->id, 'holiday_calendar' => 'UK']);

    $employee->update(['holiday_calendar' => 'IN']);

    expect(sciCalendarOf($employee))->toBe('IN')
        ->and($employee->fresh()->shift_id)->toBe($shift->id);
});

// ── 5. Office change reaches employees only through the fallback chain ─────

test('5 — an office calendar reaches only the employees who inherit it', function () {
    sciCompany('UK');

    $office = Office::factory()->create(['country' => 'India', 'holiday_calendar' => null]);
    $shift = sciShift('1PM to 10PM');

    $inherits = sciEmployee(['office_id' => $office->id, 'shift_id' => $shift->id, 'holiday_calendar' => null]);
    $overrides = sciEmployee(['office_id' => $office->id, 'shift_id' => $shift->id, 'holiday_calendar' => 'UK']);
    $elsewhere = sciEmployee(['office_id' => null, 'shift_id' => $shift->id, 'holiday_calendar' => null]);

    // Office says nothing yet — everyone falls through to the company default.
    expect(sciCalendarOf($inherits))->toBe('UK');

    $office->update(['holiday_calendar' => 'IN']);

    expect(sciCalendarOf($inherits))->toBe('IN')       // inherited
        ->and(sciCalendarOf($overrides))->toBe('UK')   // own value wins
        ->and(sciCalendarOf($elsewhere))->toBe('UK');  // different office, untouched
});

// ── 6. Employee override has the highest priority ──────────────────────────

test('6 — the employee override beats office and company', function () {
    sciCompany('IN');

    $office = Office::factory()->create(['country' => 'India', 'holiday_calendar' => 'IN']);
    $employee = sciEmployee([
        'office_id' => $office->id,
        'shift_id' => sciShift('10.30 AM to 7.30 PM', '10:30', '19:30')->id,
        'holiday_calendar' => 'UK',
    ]);

    expect(sciCalendarOf($employee))->toBe('UK');
});

// ── 7. Shift names are inert, whatever they say ────────────────────────────

test('7 — no shift name influences calendar resolution', function () {
    // Names deliberately chosen to bait every plausible substring rule.
    sciCompany('UK');

    $names = ['UK Sales Shift', 'India Shift', 'IN Night', 'London Hours', 'Mumbai Day', 'GB Late', 'UK', '1PM to 10PM'];

    foreach ($names as $index => $name) {
        $employee = sciEmployee([
            'shift_id' => sciShift($name.' '.$index)->id,
            'holiday_calendar' => null,
            'office_id' => null,
        ]);

        // Company default, every time — the name contributes nothing.
        expect(sciCalendarOf($employee))->toBe('UK');
    }
});

test('7b — the same shift names are equally inert under an India default', function () {
    sciCompany('IN');

    foreach (['UK Sales Shift', 'London Hours', 'GB Late'] as $index => $name) {
        $employee = sciEmployee([
            'shift_id' => sciShift($name.' '.$index)->id,
            'holiday_calendar' => null,
            'office_id' => null,
        ]);

        expect(sciCalendarOf($employee))->toBe('IN');
    }
});

// ── Structural guard ───────────────────────────────────────────────────────

test('calendar resolution reads no shift, department or name', function () {
    // Guards the regression directly rather than only its symptoms: if a future
    // change reaches for any of these to decide a calendar, this fails.
    //
    // Scoped to the resolution methods on purpose. Elsewhere in the class
    // department_id and office_id are read legitimately, to decide which
    // employees a scoped holiday covers — that is scope, not calendar.
    $source = file_get_contents(app_path('Services/Attendance/HolidayResolver.php'));

    $start = strpos($source, 'public function resolveCountry');
    $end = strpos($source, 'public function forEmployeeOn');
    $resolution = substr($source, $start, $end - $start);

    // Strip comments — the class documents the old bug on purpose.
    $code = preg_replace('~/\*.*?\*/~s', '', $resolution);
    $code = preg_replace('~//.*$~m', '', $code);

    expect($code)->not->toContain('shift')
        ->and($code)->not->toContain('Shift')
        ->and($code)->not->toContain('department')
        ->and($code)->not->toContain('->name')
        ->and($code)->not->toContain('str_contains')
        ->and($code)->not->toContain('start_time')
        // What it may read: the three explicit calendar fields, nothing else.
        ->and($code)->toContain('holiday_calendar');
});

test('an employee with no shift at all still resolves a calendar', function () {
    // Shift is not an input, so its absence must not be an obstacle either.
    sciCompany('UK');

    expect(sciCalendarOf(sciEmployee(['shift_id' => null, 'holiday_calendar' => null, 'office_id' => null])))->toBe('UK');
});
