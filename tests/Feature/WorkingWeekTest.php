<?php

use App\Livewire\Attendance\AttendanceSettings;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceReportBuilder;
use Illuminate\Support\Carbon;

/**
 * The working week used to be hardcoded to "Sunday only" in ~20 places, so a
 * company on a five-day week had every Saturday counted as an absence —
 * understating attendance and overstating loss of pay in payroll.
 */
beforeEach(function () {
    AttendanceSetting::flushWeeklyOffCache();
});

function setWeeklyOffs(?array $days): void
{
    AttendanceSetting::firstOrCreate([])->update(['weekly_off_days' => $days]);
    AttendanceSetting::flushWeeklyOffCache();
}

/** Mon 1 Jun 2026 → Sun 7 Jun 2026. */
function juneWeek(): array
{
    return ['from' => '2026-06-01', 'to' => '2026-06-07'];
}

test('the working week defaults to Saturday and Sunday off (Conexus)', function () {
    setWeeklyOffs(null);

    expect(AttendanceSetting::weeklyOffDays())->toEqualCanonicalizing([Carbon::SATURDAY, Carbon::SUNDAY])
        ->and(AttendanceSetting::isWeeklyOff(Carbon::parse('2026-06-07')))->toBeTrue()  // Sunday
        ->and(AttendanceSetting::isWeeklyOff(Carbon::parse('2026-06-06')))->toBeTrue()  // Saturday
        ->and(AttendanceSetting::workingDaysBetween(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07')))->toBe(5);
});

test('a five-day week makes Saturday a weekly off', function () {
    setWeeklyOffs([Carbon::SATURDAY, Carbon::SUNDAY]);

    expect(AttendanceSetting::isWeeklyOff(Carbon::parse('2026-06-06')))->toBeTrue()
        ->and(AttendanceSetting::isWeeklyOff(Carbon::parse('2026-06-07')))->toBeTrue()
        ->and(AttendanceSetting::isWeeklyOff(Carbon::parse('2026-06-05')))->toBeFalse()
        ->and(AttendanceSetting::workingDaysBetween(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07')))->toBe(5);
});

test('a configuration marking every day off falls back rather than making everyone absent', function () {
    setWeeklyOffs([0, 1, 2, 3, 4, 5, 6]);

    expect(AttendanceSetting::weeklyOffDays())->toEqualCanonicalizing([Carbon::SATURDAY, Carbon::SUNDAY]);
});

test('an out-of-range day number is ignored', function () {
    setWeeklyOffs([6, 99, -2]);

    expect(AttendanceSetting::weeklyOffDays())->toBe([Carbon::SATURDAY]);
});

/** Present on Monday only: attendance is being recorded, so the rest of the week is absent. */
function weekPersonPresentMonday(string $name): Employee
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08']);
    Attendance::create(['employee_id' => $employee->id, 'date' => juneWeek()['from'], 'check_in' => juneWeek()['from'].' 09:00:00',
        'check_out' => juneWeek()['from'].' 18:00:00', 'status' => 'on_time', 'work_mode' => 'office']);

    return $employee;
}

test('the register counts Saturday as absent on a six-day week and as a weekly off on a five-day week', function () {
    weekPersonPresentMonday('Week Person');

    $builder = app(AttendanceReportBuilder::class);

    setWeeklyOffs([Carbon::SUNDAY]); // an explicit six-day week
    $row = $builder->build('register', juneWeek())['rows'][0];
    $totals = array_slice($row, -10); // P, A, L, HD, LV, UL, WO, H, LOP, Paid
    expect(array_slice($row, 3, 7)[5])->toBe('A')  // Saturday
        ->and($totals[1])->toBe(5.0)               // absent Tue–Sat
        ->and($totals[6])->toBe(1);                // weekly offs

    setWeeklyOffs([Carbon::SATURDAY, Carbon::SUNDAY]);
    $row = $builder->build('register', juneWeek())['rows'][0];
    $totals = array_slice($row, -10);
    expect(array_slice($row, 3, 7)[5])->toBe('WO') // Saturday
        ->and($totals[1])->toBe(4.0)               // one fewer absence
        ->and($totals[6])->toBe(2);                // two weekly offs
});

test('LOP falls and the eligible working days shrink when Saturday becomes a weekly off', function () {
    weekPersonPresentMonday('Payroll Person');

    $builder = app(AttendanceReportBuilder::class);
    $named = fn () => (function (array $report) {
        return array_combine($report['columns'], $report['rows'][0]);
    })($builder->build('payroll_attendance', juneWeek()));

    setWeeklyOffs([Carbon::SUNDAY]); // an explicit six-day week
    $sixDay = $named();

    setWeeklyOffs([Carbon::SATURDAY, Carbon::SUNDAY]);
    $fiveDay = $named();

    // Paid on actual eligible working days (Phase 4): Saturday stops being one.
    expect([$sixDay['Eligible Working Days'], $fiveDay['Eligible Working Days']])->toBe([6, 5])
        ->and($fiveDay['LOP Days'])->toBeLessThan($sixDay['LOP Days'])   // less loss of pay
        ->and([$sixDay['Paid Days'], $fiveDay['Paid Days']])->toBe([1.0, 1.0]);

    // Every eligible working day is either paid or lost, under both configurations.
    expect($sixDay['LOP Days'] + $sixDay['Paid Days'])->toBe(6.0)
        ->and($fiveDay['LOP Days'] + $fiveDay['Paid Days'])->toBe(5.0);
});

test('the settings screen saves the chosen working week and rejects an all-off week', function () {
    $admin = User::factory()->create(['role' => 'hr_admin']);

    Livewire\Livewire::actingAs($admin)
        ->test(AttendanceSettings::class)
        ->set('weeklyOffDays', [Carbon::SATURDAY, Carbon::SUNDAY])
        ->call('save');

    AttendanceSetting::flushWeeklyOffCache();
    expect(AttendanceSetting::weeklyOffDays())->toEqualCanonicalizing([Carbon::SATURDAY, Carbon::SUNDAY]);

    // Marking all seven days off must not persist.
    Livewire\Livewire::actingAs($admin)
        ->test(AttendanceSettings::class)
        ->set('weeklyOffDays', [0, 1, 2, 3, 4, 5, 6])
        ->call('save');

    AttendanceSetting::flushWeeklyOffCache();
    expect(AttendanceSetting::weeklyOffDays())->toEqualCanonicalizing([Carbon::SATURDAY, Carbon::SUNDAY]);
});
