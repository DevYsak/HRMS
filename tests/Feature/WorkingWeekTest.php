<?php

use App\Livewire\Attendance\AttendanceSettings;
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

test('the working week defaults to Sunday only, preserving previous behaviour', function () {
    setWeeklyOffs(null);

    expect(AttendanceSetting::weeklyOffDays())->toBe([Carbon::SUNDAY])
        ->and(AttendanceSetting::isWeeklyOff(Carbon::parse('2026-06-07')))->toBeTrue()  // Sunday
        ->and(AttendanceSetting::isWeeklyOff(Carbon::parse('2026-06-06')))->toBeFalse() // Saturday
        ->and(AttendanceSetting::workingDaysBetween(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07')))->toBe(6);
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

    expect(AttendanceSetting::weeklyOffDays())->toBe([Carbon::SUNDAY]);
});

test('an out-of-range day number is ignored', function () {
    setWeeklyOffs([6, 99, -2]);

    expect(AttendanceSetting::weeklyOffDays())->toBe([Carbon::SATURDAY]);
});

test('the register counts Saturday as absent on a six-day week and as a weekly off on a five-day week', function () {
    $user = User::factory()->create(['name' => 'Week Person']);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $builder = app(AttendanceReportBuilder::class);

    setWeeklyOffs(null);
    $row = $builder->build('register', juneWeek())['rows'][0];
    $totals = array_slice($row, -8); // P, A, L, HD, LV, WO, H, Payable
    expect(array_slice($row, 3, 7)[5])->toBe('A')  // Saturday
        ->and($totals[1])->toBe(6)                 // absent
        ->and($totals[5])->toBe(1);                // weekly offs

    setWeeklyOffs([Carbon::SATURDAY, Carbon::SUNDAY]);
    $row = $builder->build('register', juneWeek())['rows'][0];
    $totals = array_slice($row, -8);
    expect(array_slice($row, 3, 7)[5])->toBe('WO') // Saturday
        ->and($totals[1])->toBe(5)                 // one fewer absence
        ->and($totals[5])->toBe(2);                // two weekly offs
});

test('payable days rise and LOP falls when Saturday becomes a weekly off', function () {
    $user = User::factory()->create(['name' => 'Payroll Person']);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $builder = app(AttendanceReportBuilder::class);

    setWeeklyOffs(null);
    $sixDay = $builder->build('payroll_attendance', juneWeek())['rows'][0];

    setWeeklyOffs([Carbon::SATURDAY, Carbon::SUNDAY]);
    $fiveDay = $builder->build('payroll_attendance', juneWeek())['rows'][0];

    // Columns: … Absent(9), LOP(10), Payable(11)
    expect($fiveDay[11])->toBeGreaterThan($sixDay[11])  // more payable days
        ->and($fiveDay[10])->toBeLessThan($sixDay[10]); // less loss of pay

    // Every calendar day is still accounted for under both configurations.
    expect($sixDay[10] + $sixDay[11])->toBe(7.0)
        ->and($fiveDay[10] + $fiveDay[11])->toBe(7.0);
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
