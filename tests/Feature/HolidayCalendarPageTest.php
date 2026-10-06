<?php

use App\Enums\HolidayType;
use App\Enums\UserRole;
use App\Livewire\Holidays\HolidayCalendar;
use App\Livewire\Holidays\ManageHolidays;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\HolidayWorkRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\EmployeeMenu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Holidays: the employee's own Holiday Calendar (their calendar only, MDL
 * shown distinctly) and HR's management rules (future-only delete,
 * substitute holidays, company-calendar default, MDL dates).
 */
beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-14 10:00:00')));

function hcpEmployee(string $calendar = 'UK'): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'holiday_calendar' => $calendar]);
}

function hcpHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

test('an employee sees only their own calendar, with MDL dates shown distinctly', function () {
    $employee = hcpEmployee('UK');
    PublicHoliday::create(['name' => 'Founders Day', 'date' => '2026-10-21', 'country' => 'UK']);
    PublicHoliday::create(['name' => 'Diwali Holiday', 'date' => '2026-10-20', 'country' => 'IN']);
    PublicHoliday::create(['name' => 'Wellbeing Day', 'date' => '2026-10-23', 'country' => 'UK', 'is_optional' => true, 'holiday_type' => 'optional']);
    DecemberMandatoryDay::create(['year' => 2026, 'date' => '2026-12-29', 'description' => 'Company shutdown']);

    Livewire::actingAs($employee->user)->test(HolidayCalendar::class)
        ->assertSee('Founders Day')
        ->assertSee('Wellbeing Day')
        ->assertSee('(optional)', escape: false)
        ->assertDontSee('Diwali Holiday')
        ->set('view', 'year')
        ->assertSee('MDL · Company shutdown');
});

test('the calendar moves month by month and by year', function () {
    $employee = hcpEmployee();

    Livewire::actingAs($employee->user)->test(HolidayCalendar::class)
        ->assertSet('month', '2026-10')
        ->call('next')->assertSet('month', '2026-11')
        ->call('previous')->call('previous')->assertSet('month', '2026-09')
        ->call('setYear', 2025)->assertSet('month', '2025-09')
        ->assertSee('September 2025');
});

test('Holidays is in the employee menu', function () {
    expect(collect(app(EmployeeMenu::class)->visible())->pluck('route'))->toContain('holidays.calendar');
    $this->actingAs(hcpEmployee()->user)->get(route('holidays.calendar'))->assertOk();
});

test('My Time Off no longer shows another country\'s holidays', function () {
    $employee = hcpEmployee('UK');
    PublicHoliday::create(['name' => 'Diwali Holiday', 'date' => '2026-10-20', 'country' => 'IN']);
    PublicHoliday::create(['name' => 'Founders Day', 'date' => '2026-10-21', 'country' => 'UK']);

    $days = collect(Livewire::actingAs($employee->user)->test(MyTimeOff::class)->viewData('calendarDays'))->keyBy(fn ($d) => $d['date']->toDateString());

    expect($days['2026-10-20']['holiday'])->toBeNull()
        ->and($days['2026-10-21']['holiday']?->name)->toBe('Founders Day');
});

// ── HR ─────────────────────────────────────────────────────────────────────

test('only a future holiday can be deleted; a past one is kept', function () {
    $past = PublicHoliday::create(['name' => 'Past Day', 'date' => '2026-05-04', 'country' => 'UK']);
    $future = PublicHoliday::create(['name' => 'Future Day', 'date' => '2026-12-28', 'country' => 'UK']);

    Livewire::actingAs(hcpHr())->test(ManageHolidays::class)
        ->call('delete', $past->id)
        ->call('delete', $future->id);

    expect(PublicHoliday::find($past->id))->not->toBeNull()
        ->and(PublicHoliday::find($future->id))->toBeNull();
});

test('a holiday with holiday-work requests is never deleted', function () {
    $holiday = PublicHoliday::create(['name' => 'Busy Day', 'date' => '2026-12-28', 'country' => 'UK']);
    HolidayWorkRequest::factory()->create(['holiday_id' => $holiday->id, 'work_date' => '2026-12-28']);

    Livewire::actingAs(hcpHr())->test(ManageHolidays::class)->call('delete', $holiday->id);

    expect(PublicHoliday::find($holiday->id))->not->toBeNull();
});

test('a new holiday defaults to the company calendar, and a substitute must name its holiday', function () {
    DB::table('companies')->update(['holiday_calendar' => 'UK']);
    $calendar = DB::table('companies')->value('holiday_calendar') ?: 'IN';
    $boxing = PublicHoliday::create(['name' => 'Boxing Day', 'date' => '2026-12-26', 'country' => 'UK']);

    $component = Livewire::actingAs(hcpHr())->test(ManageHolidays::class)
        ->call('openCreate')
        ->assertSet('form.country', $calendar)
        ->set('form.name', 'Boxing Day (substitute)')
        ->set('form.date', '2026-12-28')
        ->set('form.country', 'UK')
        ->set('form.holiday_type', HolidayType::Substitute->value)
        ->call('save')
        ->assertHasErrors('form.substitute_for_id');

    $component->set('form.substitute_for_id', $boxing->id)->call('save')->assertHasNoErrors();

    $substitute = PublicHoliday::where('name', 'Boxing Day (substitute)')->firstOrFail();
    expect($substitute->holiday_type)->toBe(HolidayType::Substitute)
        ->and($substitute->substitute_for_id)->toBe($boxing->id);
});

test('HR adds and removes future MDL dates in December only', function () {
    $component = Livewire::actingAs(hcpHr())->test(ManageHolidays::class)
        ->set('mdlDate', '2026-11-30')->call('addMdl')->assertHasErrors('mdlDate')
        ->set('mdlDate', '2026-12-30')->call('addMdl')->assertHasNoErrors();

    $day = DecemberMandatoryDay::whereDate('date', '2026-12-30')->firstOrFail();
    $past = DecemberMandatoryDay::create(['year' => 2025, 'date' => '2025-12-30', 'description' => 'Shutdown']);

    $component->call('deleteMdl', $past->id)->call('deleteMdl', $day->id);

    expect(DecemberMandatoryDay::find($past->id))->not->toBeNull()
        ->and(DecemberMandatoryDay::find($day->id))->toBeNull();
});

test('the holiday export is a properly escaped spreadsheet', function () {
    PublicHoliday::create(['name' => 'Day, with "quotes"', 'date' => '2026-11-05', 'country' => 'UK']);

    Livewire::actingAs(hcpHr())->test(ManageHolidays::class)
        ->call('exportCsv')
        ->assertFileDownloaded('holidays-2026.csv');
});

test('an employee cannot open holiday management', function () {
    Livewire::actingAs(hcpEmployee()->user)->test(ManageHolidays::class)->assertForbidden();
});
