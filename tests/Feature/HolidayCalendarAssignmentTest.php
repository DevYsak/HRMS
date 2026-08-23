<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Office;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\HolidayResolver;
use Illuminate\Support\Carbon;

/**
 * Which holiday calendar an employee follows is now an explicit setting.
 *
 * It used to be inferred from whether the shift NAME contained "UK". Conexus's
 * UK Operations shift is called "1PM to 10PM", so the entire UK team was
 * silently placed on the Indian calendar — wrong public holidays, and
 * attendance scored against the wrong non-working days.
 */
function hcaCompany(string $calendar = 'UK'): Company
{
    Company::query()->delete();

    return Company::create(['name' => 'Conexus Technologies', 'country' => 'India', 'holiday_calendar' => $calendar]);
}

function hcaEmployee(array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create($attributes + ['user_id' => $user->id, 'status' => 'active']);
}

// ── The defect that started this ───────────────────────────────────────────

test('the UK Operations shift no longer decides the calendar', function () {
    hcaCompany('UK');

    // The real shift, named after its hours rather than its region. Under the
    // old str_contains(name, 'UK') rule this resolved to India.
    $shift = ShiftSetting::create(['name' => '1PM to 10PM', 'start_time' => '13:00', 'end_time' => '22:00', 'grace_minutes' => 5]);
    $employee = hcaEmployee(['shift_id' => $shift->id, 'office_id' => null]);

    expect(app(HolidayResolver::class)->resolveCountry($employee))->toBe('UK');
});

test('a shift whose name contains UK no longer forces the UK calendar', function () {
    // The mirror of the bug: the name must not be load-bearing in either
    // direction. A company on the India calendar keeps it.
    hcaCompany('IN');

    $shift = ShiftSetting::create(['name' => 'UK Sales Shift', 'start_time' => '13:00', 'end_time' => '22:00', 'grace_minutes' => 5]);
    $employee = hcaEmployee(['shift_id' => $shift->id, 'office_id' => null]);

    expect(app(HolidayResolver::class)->resolveCountry($employee))->toBe('IN');
});

// ── The resolution chain ───────────────────────────────────────────────────

test('the company default applies when nothing more specific is set', function () {
    hcaCompany('UK');

    expect(app(HolidayResolver::class)->resolveCountry(hcaEmployee(['office_id' => null])))->toBe('UK');
});

test('an office calendar overrides the company default', function () {
    hcaCompany('UK');
    $office = Office::factory()->create(['country' => 'India', 'holiday_calendar' => 'IN']);

    expect(app(HolidayResolver::class)->resolveCountry(hcaEmployee(['office_id' => $office->id])))->toBe('IN');
});

test('an employee override beats both office and company', function () {
    hcaCompany('UK');
    $office = Office::factory()->create(['country' => 'India', 'holiday_calendar' => 'IN']);

    $employee = hcaEmployee(['office_id' => $office->id, 'holiday_calendar' => 'UK']);

    expect(app(HolidayResolver::class)->resolveCountry($employee))->toBe('UK');
});

test('an office address does not decide the calendar on its own', function () {
    // An India-based office under a UK-policy company keeps the UK calendar
    // until somebody says otherwise. Where the desk sits is not the contract.
    hcaCompany('UK');
    $office = Office::factory()->create(['country' => 'India', 'holiday_calendar' => null]);

    expect(app(HolidayResolver::class)->resolveCountry(hcaEmployee(['office_id' => $office->id])))->toBe('UK');
});

// ── End to end ─────────────────────────────────────────────────────────────

test('a UK-default employee gets UK bank holidays and not Indian ones', function () {
    hcaCompany('UK');
    $employee = hcaEmployee(['office_id' => null]);

    PublicHoliday::create(['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'country' => 'UK', 'jurisdiction' => 'england-and-wales']);
    PublicHoliday::create(['date' => '2026-11-09', 'name' => 'Diwali', 'country' => 'IN']);

    $resolver = app(HolidayResolver::class);

    expect($resolver->isHoliday($employee, Carbon::parse('2026-08-31')))->toBeTrue()
        ->and($resolver->isHoliday($employee, Carbon::parse('2026-11-09')))->toBeFalse();
});

test('the 2026 England and Wales bank holidays all resolve for a UK employee', function () {
    // The eight dates GOV.UK lists for England & Wales in 2026.
    hcaCompany('UK');
    $employee = hcaEmployee(['office_id' => null]);

    $dates = [
        '2026-01-01' => 'New Year Day',
        '2026-04-03' => 'Good Friday',
        '2026-04-06' => 'Easter Monday',
        '2026-05-04' => 'Early May Bank Holiday',
        '2026-05-25' => 'Spring Bank Holiday',
        '2026-08-31' => 'Summer Bank Holiday',
        '2026-12-25' => 'Christmas Day',
        '2026-12-28' => 'Boxing Day substitute',
    ];

    foreach ($dates as $date => $name) {
        PublicHoliday::create(['date' => $date, 'name' => $name, 'country' => 'UK', 'jurisdiction' => 'england-and-wales']);
    }

    $resolver = app(HolidayResolver::class);

    foreach (array_keys($dates) as $date) {
        expect($resolver->isHoliday($employee, Carbon::parse($date)))->toBeTrue();
    }
});

test('jurisdictions can coexist on the same UK calendar', function () {
    // Scotland takes its summer bank holiday in early August; England & Wales
    // at the end. Both are country=UK, and the table can now say which.
    hcaCompany('UK');

    PublicHoliday::create(['date' => '2026-08-03', 'name' => 'Summer Bank Holiday', 'country' => 'UK', 'jurisdiction' => 'scotland']);
    PublicHoliday::create(['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'country' => 'UK', 'jurisdiction' => 'england-and-wales']);

    expect(PublicHoliday::where('country', 'UK')->distinct()->pluck('jurisdiction')->sort()->values()->all())
        ->toBe(['england-and-wales', 'scotland']);
});

test('holidays record where their dates came from', function () {
    $holiday = PublicHoliday::create([
        'date' => '2026-05-04', 'name' => 'Early May Bank Holiday', 'country' => 'UK',
        'jurisdiction' => 'england-and-wales', 'source' => 'https://www.gov.uk/bank-holidays',
    ]);

    expect($holiday->fresh()->source)->toBe('https://www.gov.uk/bank-holidays');
});
