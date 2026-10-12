<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ShiftSetting;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * My Attendance — Attendance History filters, pagination, sorting, export and
 * query-string state. Filtering runs on the server over the month's canonical
 * rows (monthHistory); weekly off, holiday and leave are never Absent.
 *
 * October 2026 fixture (today = Wed 14 Oct):
 *   01 Thu present office · 02 Fri late office · 03/04 weekly off
 *   05 Mon WFH · 06 Tue leave · 07 Wed regularised office · 08 Thu auto checkout
 *   09 Fri missing checkout · 10/11 weekly off · 12 Mon absent · 13 Tue hybrid
 */
function ahfEmployee(): Employee
{
    $shift = ShiftSetting::firstOrCreate(['code' => 'AHF_DAY'], [
        'name' => 'Filter Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);

    return Employee::factory()->create(['shift_id' => $shift->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
}

function ahfDay(Employee $employee, string $date, array $attributes = []): Attendance
{
    return Attendance::create($attributes + [
        'employee_id' => $employee->id, 'date' => $date,
        'check_in' => "$date 09:00:00", 'check_out' => "$date 18:00:00",
        'status' => 'on_time', 'work_mode' => 'office',
    ]);
}

function ahfMonth(Employee $employee): void
{
    ahfDay($employee, '2026-10-01');
    ahfDay($employee, '2026-10-02', ['check_in' => '2026-10-02 09:40:00', 'status' => 'late', 'is_late' => true, 'late_minutes' => 35]);
    ahfDay($employee, '2026-10-05', ['work_mode' => 'wfh']);
    $type = LeaveType::firstOrCreate(['code' => 'AHF'], ['name' => 'Test Leave', 'category' => 'other', 'color' => '#999999', 'allow_paid_request' => true]);
    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-06', 'end_date' => '2026-10-06',
        'days' => 1, 'is_half_day' => false, 'reason' => 'Test', 'status' => 'approved', 'requested_leave_status' => 'paid',
    ]);
    ahfDay($employee, '2026-10-07', ['is_regularized' => true]);
    ahfDay($employee, '2026-10-08', ['check_in' => '2026-10-08 09:05:00', 'is_auto_checkout' => true, 'auto_checkout_reason' => Attendance::AUTO_CHECKOUT_REASON, 'check_out_method' => 'auto']);
    ahfDay($employee, '2026-10-09', ['check_out' => null]);
    ahfDay($employee, '2026-10-13', ['work_mode' => 'hybrid']);
}

/** The dates shown in the history table, in table order. */
function ahfRows($component): array
{
    preg_match_all('/data-history-row="([\d-]+)"/', $component->html(), $m);

    return $m[1];
}

/** Apply draft filters the way the form does. */
function ahfApply($component, array $filters)
{
    foreach ($filters as $key => $value) {
        $component->set('filter'.ucfirst($key), $value);
    }

    return $component->call('applyHistoryFilters');
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $this->employee = ahfEmployee();
    ahfMonth($this->employee);
    $this->page = fn () => Livewire::actingAs($this->employee->user)->test(AttendanceTracker::class);
});

test('the unfiltered history lists the month to today, newest first', function () {
    $c = ($this->page)();

    expect(ahfRows($c))->toBe(['2026-10-14', '2026-10-13', '2026-10-12', '2026-10-11', '2026-10-10', '2026-10-09', '2026-10-08', '2026-10-07', '2026-10-06', '2026-10-05'])
        ->and($c->html())->toContain('Showing 1–10 of 14 records');
});

test('1 · From only keeps days on or after it', function () {
    $c = ahfApply(($this->page)(), ['from' => '2026-10-08'])->assertHasNoErrors();

    expect(ahfRows($c))->toBe(['2026-10-14', '2026-10-13', '2026-10-12', '2026-10-11', '2026-10-10', '2026-10-09', '2026-10-08'])
        ->and($c->get('historyFrom'))->toBe('2026-10-08');
});

test('2 · To only keeps days on or before it', function () {
    $c = ahfApply(($this->page)(), ['to' => '2026-10-05']);

    expect(ahfRows($c))->toBe(['2026-10-05', '2026-10-04', '2026-10-03', '2026-10-02', '2026-10-01']);
});

test('3 · a valid From + To keeps only that range', function () {
    $c = ahfApply(($this->page)(), ['from' => '2026-10-05', 'to' => '2026-10-07']);

    expect(ahfRows($c))->toBe(['2026-10-07', '2026-10-06', '2026-10-05']);
});

test('4 · To before From is refused inline and nothing is applied', function () {
    $c = ahfApply(($this->page)(), ['from' => '2026-10-07', 'to' => '2026-10-05'])
        ->assertHasErrors(['filterTo'])
        ->assertSee('The To date cannot be before the From date.')
        ->assertSeeHtml('data-filter-error="to"')
        ->assertSet('historyFrom', '')
        ->assertSet('historyTo', '');

    expect(ahfRows($c))->toHaveCount(10);
});

test('dates outside the month, or after today, are refused', function () {
    ahfApply(($this->page)(), ['from' => '2026-09-30'])
        ->assertHasErrors(['filterFrom'])
        ->assertSee('Choose a date within 01 Oct 2026 – 14 Oct 2026.')
        ->assertSet('historyFrom', '');

    ahfApply(($this->page)(), ['to' => '2026-10-20'])->assertHasErrors(['filterTo'])->assertSet('historyTo', '');
    ahfApply(($this->page)(), ['from' => 'not-a-date'])->assertHasErrors(['filterFrom']);
});

test('5 · Present keeps attended days (WFH and hybrid too), never late, leave, absent or off days', function () {
    $c = ahfApply(($this->page)(), ['status' => 'present']);

    expect(ahfRows($c))->toBe(['2026-10-13', '2026-10-09', '2026-10-08', '2026-10-07', '2026-10-05', '2026-10-01']);
});

test('6 · Late keeps only late days', function () {
    expect(ahfRows(ahfApply(($this->page)(), ['status' => 'late'])))->toBe(['2026-10-02']);
});

test('7 · Weekly Off lists the weekends, and Absent never does', function () {
    expect(ahfRows(ahfApply(($this->page)(), ['status' => 'weekly_off'])))->toBe(['2026-10-11', '2026-10-10', '2026-10-04', '2026-10-03'])
        ->and(ahfRows(ahfApply(($this->page)(), ['status' => 'absent'])))->toBe(['2026-10-12']);
});

test('8 · On Leave lists the leave day, and it is never Absent', function () {
    $c = ahfApply(($this->page)(), ['status' => 'leave']);

    expect(ahfRows($c))->toBe(['2026-10-06'])
        ->and(ahfRows(ahfApply(($this->page)(), ['status' => 'absent'])))->not->toContain('2026-10-06');
});

test('9 · Regularised keeps only the corrected day', function () {
    expect(ahfRows(ahfApply(($this->page)(), ['status' => 'regularised'])))->toBe(['2026-10-07']);
});

test('10 · Auto Checkout is its own status and never shows as a missing or normal checkout', function () {
    $auto = ahfApply(($this->page)(), ['status' => 'auto_checkout']);

    expect(ahfRows($auto))->toBe(['2026-10-08'])
        ->and($auto->html())->toContain('Auto Checkout')
        ->and(ahfRows(ahfApply(($this->page)(), ['status' => 'missing_checkout'])))->toBe(['2026-10-09']);
});

test('11 · Office mode keeps office days only', function () {
    expect(ahfRows(ahfApply(($this->page)(), ['mode' => 'office'])))->toBe(['2026-10-09', '2026-10-08', '2026-10-07', '2026-10-02', '2026-10-01']);
});

test('12 · WFH mode keeps WFH days only', function () {
    expect(ahfRows(ahfApply(($this->page)(), ['mode' => 'wfh'])))->toBe(['2026-10-05'])
        ->and(ahfRows(ahfApply(($this->page)(), ['mode' => 'hybrid'])))->toBe(['2026-10-13']);
});

test('13 · date + status + mode combine', function () {
    $c = ahfApply(($this->page)(), ['from' => '2026-10-01', 'to' => '2026-10-10', 'status' => 'present', 'mode' => 'office']);

    expect(ahfRows($c))->toBe(['2026-10-09', '2026-10-08', '2026-10-07', '2026-10-01'])
        ->and($c->html())->toContain('Showing 1–4 of 4 records (filtered from 14)');
});

test('14 · Clear resets every filter and keeps the month', function () {
    $c = ($this->page)()->call('historyPreviousMonth')->call('historyNextMonth');
    ahfApply($c, ['from' => '2026-10-05', 'to' => '2026-10-07', 'status' => 'leave', 'mode' => 'office']);

    $c->call('clearHistoryFilters')
        ->assertSet('historyFrom', '')->assertSet('historyTo', '')
        ->assertSet('historyStatus', '')->assertSet('historyMode', '')
        ->assertSet('filterFrom', '')->assertSet('filterStatus', '')
        ->assertSet('historyPage', 1)
        ->assertSet('historyMonth', '2026-10');

    expect(ahfRows($c))->toHaveCount(10);
});

test('15 · no match shows the empty state with Clear Filters, not a blank table', function () {
    ahfApply(($this->page)(), ['status' => 'late', 'mode' => 'wfh'])
        ->assertSeeHtml('data-history-no-match')
        ->assertSee('No attendance records match these filters.')
        ->assertSee('Clear Filters')
        ->assertDontSeeHtml('data-history-table');
});

test('16 · changing page keeps the filters', function () {
    $this->travelTo(Carbon::parse('2026-10-31 20:00:00'));
    foreach (['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'] as $date) {
        ahfDay($this->employee, $date);
    }

    $c = ahfApply(($this->page)(), ['mode' => 'office']);
    expect(ahfRows($c))->toHaveCount(10)
        ->and($c->html())->toContain('Showing 1–10 of 18 records');

    $c->call('setHistoryPage', 2)->assertSet('historyPage', 2)->assertSet('historyMode', 'office');

    // Page 2 holds the 11th–18th office days, newest first.
    expect(ahfRows($c))->toBe(['2026-10-16', '2026-10-15', '2026-10-14', '2026-10-09', '2026-10-08', '2026-10-07', '2026-10-02', '2026-10-01'])
        ->and($c->html())->toContain('Showing 11–18 of 18 records');

    // A bigger page size goes back to page 1, filters kept.
    $c->set('historyPerPage', 20)->assertSet('historyPage', 1)->assertSet('historyMode', 'office');
    expect(ahfRows($c))->toHaveCount(18);
});

test('17 · a new filter goes back to page 1', function () {
    $c = ($this->page)()->call('setHistoryPage', 2)->assertSet('historyPage', 2);

    ahfApply($c, ['status' => 'present'])->assertSet('historyPage', 1);
});

test('18 · export writes exactly the filtered rows', function () {
    $c = ahfApply(($this->page)(), ['from' => '2026-10-01', 'to' => '2026-10-10', 'status' => 'present', 'mode' => 'office'])
        ->call('exportHistory')
        ->assertFileDownloaded('attendance-history-2026-10-filtered.csv');

    $csv = base64_decode($c->effects['download']['content']);
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toBe('Date,Day,Status,"First In","Last Out",Worked,Break,Exception,Mode')
        ->and(array_map(fn ($l) => substr($l, 0, 10), array_slice($lines, 1)))->toBe(['2026-10-09', '2026-10-08', '2026-10-07', '2026-10-01'])
        ->and($csv)->toContain('Auto Checkout')
        ->and($csv)->not->toContain('2026-10-02')
        ->and($csv)->not->toContain('2026-10-05');
});

test('19 · an employee only ever sees and exports their own history', function () {
    $other = ahfEmployee();
    ahfDay($other, '2026-10-01', ['check_in' => '2026-10-01 07:13:00', 'check_out' => '2026-10-01 16:47:00']);

    $c = ($this->page)()->assertDontSee('07:13')->assertDontSee('16:47');
    $csv = base64_decode($c->call('exportHistory')->effects['download']['content']);
    expect($csv)->not->toContain('07:13')->and($csv)->toContain('09:00');

    // The page itself needs a signed-in user.
    auth()->logout();
    $this->get(route('attendance.my'))->assertRedirect();
});

test('20 · a new month drops dates that no longer fit and keeps status and mode', function () {
    $c = ahfApply(($this->page)(), ['from' => '2026-10-02', 'to' => '2026-10-09', 'status' => 'late', 'mode' => 'office'])
        ->call('setHistoryPage', 1)
        ->call('historyPreviousMonth')
        ->assertSet('historyMonth', '2026-09')
        ->assertSet('historyFrom', '')->assertSet('historyTo', '')
        ->assertSet('filterFrom', '')->assertSet('filterTo', '')
        ->assertSet('historyStatus', 'late')->assertSet('historyMode', 'office')
        ->assertSet('historyPage', 1);

    expect($c->html())->toContain('min="2026-09-01"')->and($c->html())->toContain('max="2026-09-30"');
});

test('filters live in the query string and are checked on load', function () {
    Livewire::withQueryParams(['from' => '2026-10-05', 'to' => '2026-10-07', 'status' => 'leave'])
        ->actingAs($this->employee->user)
        ->test(AttendanceTracker::class)
        ->assertSet('historyMonth', '2026-10')
        ->assertSet('filterStatus', 'leave')
        ->assertSeeHtml('data-history-row="2026-10-06"')
        ->assertDontSeeHtml('data-history-row="2026-10-05"');

    // A stale or invalid URL never leaks: a date outside the month and an unknown status are dropped.
    Livewire::withQueryParams(['month' => '2026-09', 'from' => '2026-10-02', 'status' => 'bogus', 'per' => 7])
        ->actingAs($this->employee->user)
        ->test(AttendanceTracker::class)
        ->assertSet('historyMonth', '2026-09')
        ->assertSet('historyFrom', '')
        ->assertSet('historyStatus', '')
        ->assertSet('historyPerPage', 10);
});

test('sorting by worked time and status, newest first by default', function () {
    $c = ahfApply(($this->page)(), ['status' => 'present'])->call('sortHistory', 'worked');
    expect($c->get('historySort'))->toBe('worked')->and($c->get('historyDir'))->toBe('desc');

    $c->call('sortHistory', 'date')->call('sortHistory', 'date');
    expect(ahfRows($c))->toBe(['2026-10-01', '2026-10-05', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-13']);
});
