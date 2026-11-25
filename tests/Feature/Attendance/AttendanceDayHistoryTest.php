<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceRegister;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The Employees Attendance log, date by date: each row's day from the shared
 * AttendanceRegister (worked against the shift, the timeline bar, missed
 * punches), a heading per date with that date's totals, and the Day history
 * an HR opens from a row — sessions, every punch, what changed the day and
 * the raw device log — plus "Correct this day".
 *
 * Clock: Wed 4 Nov 2026, 15:00. Shift 10:30–19:30 with 5 minutes' grace;
 * Saturday and Sunday off. Face = IN, ID card = OUT.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-04 15:00:00'));
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();
    $this->shift = ShiftSetting::create([
        'name' => 'IT Shift', 'code' => 'DH_IT', 'start_time' => '10:30:00', 'end_time' => '19:30:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);

    $this->sales = Department::factory()->create(['name' => 'Sales']);
    $this->ops = Department::factory()->create(['name' => 'Operations']);
    $this->hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'Hana HR']);
    $this->pratish = dhEmployee('PRATISH KAVADE', 'CNS042', $this->sales);
    $this->kajal = dhEmployee('KAJAL KAHAR', 'CNS053', $this->sales);
    $this->saad = dhEmployee('SAAD MULLA', 'CNS040', $this->ops);

    // Pratish, Monday: late, three sessions with two breaks.
    dhDay($this->pratish, '2026-11-02', [['10:57', 'face'], ['11:33', 'id_card'], ['11:36', 'face'], ['12:12', 'id_card'], ['12:30', 'face'], ['17:00', 'id_card']],
        ['is_late' => true, 'late_minutes' => 22, 'status' => 'late', 'check_out' => '17:00']);
    // Kajal, Tuesday: in at 09:30, never out.
    $this->kajalRow = dhDay($this->kajal, '2026-11-03', [['09:30', 'face']], ['status' => 'on_time']);
    // Saad, Tuesday: a clean day.
    dhDay($this->saad, '2026-11-03', [['09:00', 'face'], ['18:00', 'id_card']], ['status' => 'on_time', 'check_out' => '18:00']);
});

function dhEmployee(string $name, string $code, Department $department): Employee
{
    return Employee::factory()->create(['user_id' => User::factory()->create(['name' => $name])->id, 'status' => 'active',
        'employee_id' => $code, 'joining_date' => '2024-01-01', 'department_id' => $department->id, 'shift_id' => test()->shift->id]);
}

/** @param  array<int, array{0: string, 1: string}>  $punches */
function dhDay(Employee $employee, string $date, array $punches, array $attendance): Attendance
{
    foreach ($punches as [$time, $method]) {
        AttendancePunch::create(['employee_id' => $employee->id, 'punched_at' => "{$date} {$time}:00", 'punch_date' => $date,
            'method' => $method, 'source' => 'biometric', 'device_serial' => 'MB20']);
    }
    $checkOut = $attendance['check_out'] ?? null;
    unset($attendance['check_out']);

    return Attendance::create($attendance + ['employee_id' => $employee->id, 'date' => $date, 'check_in' => "{$date} {$punches[0][0]}:00",
        'check_out' => $checkOut ? "{$date} {$checkOut}:00" : null, 'check_in_method' => 'face', 'work_mode' => 'office']);
}

function dhPage(?User $viewer = null)
{
    return Livewire::actingAs($viewer ?? test()->hr)->test(AllAttendance::class)->set('dateFrom', '2026-11-02')->set('dateTo', '2026-11-04');
}

test('the log is grouped by date, in order of arrival, with each date\'s totals', function () {
    $page = dhPage()->assertOk()
        ->assertSeeHtml('data-log-date="2026-11-03"')
        ->assertSeeHtml('data-log-date="2026-11-02"')
        ->assertSeeHtml('data-day-history-open=');

    $html = $page->html();
    $groups = $page->viewData('dayGroups');
    $order = collect($page->viewData('attendances')->items())->map(fn (Attendance $a) => $a->date->toDateString().' '.$a->employee_id)->all();

    // Newest date first; on Tuesday Saad (09:00) before Kajal (09:30).
    expect(strpos($html, 'data-log-date="2026-11-03"'))->toBeLessThan(strpos($html, 'data-log-date="2026-11-02"'))
        ->and($order)->toBe(['2026-11-03 '.$this->saad->id, '2026-11-03 '.$this->kajal->id, '2026-11-02 '.$this->pratish->id])
        ->and($groups['2026-11-03'])->toMatchArray(['records' => 2, 'present' => 2, 'late' => 0, 'missed' => 1])
        ->and($groups['2026-11-02'])->toMatchArray(['records' => 1, 'present' => 1, 'late' => 1])
        // One time scale per date: from the earliest punch or shift start to the latest, in whole hours.
        ->and($groups['2026-11-03']['window'])->toBe(['from' => 540, 'to' => 1200]);
});

test('each row shows worked against the shift and the day as a timeline', function () {
    $lines = dhPage()->viewData('lines');
    $pratish = $lines[$this->pratish->id.'|2026-11-02'];
    $kajal = $lines[$this->kajal->id.'|2026-11-03'];

    // 10:57 → 17:00 is 6h 03m of a 9-hour shift; the bar is each IN to its OUT, the shift band behind it.
    expect($pratish)->toMatchArray(['worked_minutes' => 363, 'expected_minutes' => 540, 'progress' => 'on_track', 'break_count' => 2,
        'punch_count' => 6, 'shift_from' => 630, 'shift_to' => 1170, 'late' => true, 'late_minutes' => 22])
        ->and($pratish['segments'])->toBe([
            ['from' => 657, 'to' => 693, 'kind' => 'work'],
            ['from' => 696, 'to' => 732, 'kind' => 'work'],
            ['from' => 750, 'to' => 1020, 'kind' => 'work'],
        ])
        ->and(collect($pratish['ticks'])->pluck('dir')->all())->toBe(['IN', 'OUT', 'IN', 'OUT', 'IN', 'OUT']);

    // No check-out: an open stretch to the shift end, and the missed check-out marked where it was missed.
    expect($kajal)->toMatchArray(['missing_checkout' => true, 'missed_count' => 1])
        ->and($kajal['segments'])->toBe([['from' => 570, 'to' => 1170, 'kind' => 'open']])
        ->and($kajal['marks'])->toBe([['at' => 570, 'title' => 'Missed check-out', 'resolved' => false]]);

    dhPage()->assertSeeHtml('data-segment="open"')->assertSeeHtml('data-mark')->assertSee('Not counted until the day is regularised');
});

test('History opens one person\'s date and steps day by day, never past today', function () {
    $page = dhPage()->call('openDayHistory', $this->pratish->id, '2026-11-02')
        ->assertSeeHtml('data-day-history="'.$this->pratish->id.'|2026-11-02"')
        ->assertSee('Monday, 02 Nov 2026')
        ->assertSeeHtml('data-history-punch="counted"')
        ->assertSee('Out for 18m');

    $history = $page->viewData('dayHistory');
    expect($history['sessions'])->toHaveCount(3)
        ->and(collect($history['sessions'])->pluck('break_after')->all())->toBe([3, 18, 0])
        ->and($history['raw'])->toHaveCount(6)
        ->and($history['events'])->toBe([]);

    $page->call('shiftHistoryDate', 1)->assertSet('historyDate', '2026-11-03')
        ->call('shiftHistoryDate', 1)->assertSet('historyDate', '2026-11-04')
        ->call('shiftHistoryDate', 1)->assertSet('historyDate', '2026-11-04')   // tomorrow is not offered
        ->call('closeDayHistory')
        ->assertSet('historyEmployeeId', null)
        ->assertDontSeeHtml('data-day-history=');
});

test('History tells what changed the day: requests with every step, deleted ones, system events and corrected times', function () {
    $this->travelTo(Carbon::parse('2026-11-03 20:00:00'));
    AttendanceRegularisation::create([
        'employee_id' => $this->kajal->id, 'attendance_id' => $this->kajalRow->id, 'work_date' => '2026-11-03',
        'requested_check_in' => '2026-11-03 09:30:00', 'requested_check_out' => '2026-11-03 18:30:00',
        'reason' => 'Forgot to punch out', 'status' => 'approved', 'stage' => 'hr_review',
        'approval_trail' => [['stage' => 'hr_review', 'action' => 'approved', 'name' => 'Hana HR', 'comment' => 'Seen on CCTV', 'at' => '2026-11-04 10:00:00']],
    ]);
    $withdrawn = AttendanceRegularisation::create([
        'employee_id' => $this->kajal->id, 'work_date' => '2026-11-03',
        'requested_check_in' => '2026-11-03 09:30:00', 'requested_check_out' => '2026-11-03 19:00:00',
        'reason' => 'Duplicate request', 'status' => 'pending', 'stage' => 'hr_review',
        'approval_trail' => [['stage' => 'hr_review', 'action' => 'deleted', 'name' => 'Hana HR', 'comment' => 'Raised twice', 'at' => '2026-11-04 09:00:00']],
    ]);
    $withdrawn->delete();
    $this->travelTo(Carbon::parse('2026-11-04 02:00:00'));
    app(AuditService::class)->event('ATTENDANCE_AUTO_CHECKOUT', AuditService::ATTENDANCE, $this->kajalRow, reason: 'No OUT punch by the cutoff');
    $this->kajalRow->update(['original_check_out' => null, 'original_check_in' => '2026-11-03 09:45:00']);
    $this->travelTo(Carbon::parse('2026-11-04 15:00:00'));

    $history = dhPage()->call('openDayHistory', $this->kajal->id, '2026-11-03')
        ->assertSee('What changed this day')
        ->assertSee('Seen on CCTV')
        ->assertSeeHtml('data-history-request="deleted"')
        ->viewData('dayHistory');

    // In time order (audit rows carry the database clock, so the system event is checked on its own).
    $people = collect($history['events'])->where('kind', '!=', 'system');
    expect($people->pluck('title')->all())->toBe([
        'Regularisation requested',
        'Regularisation requested',
        'Request deleted · HR stage',
        'Approved · HR stage',
    ])
        ->and($people->pluck('by')->all())->toBe(['KAJAL KAHAR', 'KAJAL KAHAR', 'Hana HR', 'Hana HR'])
        ->and(collect($history['events'])->firstWhere('kind', 'system'))->toMatchArray([
            'title' => 'Closed by the nightly auto check-out', 'by' => 'System', 'detail' => 'No OUT punch by the cutoff'])
        ->and(collect($history['requests'])->pluck('status')->all())->toBe(['approved', 'deleted'])
        ->and($history['corrections'])->toBe(['Check-in' => ['from' => '09:45 AM', 'to' => '09:30 AM']]);
});

test('History and Correct this day stay inside the viewer\'s reach', function () {
    $scopedHr = User::factory()->create(['role' => UserRole::HrAdmin, 'scope_departments' => [$this->sales->id]]);

    dhPage($scopedHr)->call('openDayHistory', $this->saad->id, '2026-11-03')->assertForbidden();
    dhPage($scopedHr)->call('openDayHistory', $this->kajal->id, '2026-11-03')->assertSeeHtml('data-day-history=');
    dhPage()->call('openDayHistory', $this->kajal->id, '2026-11-05')->assertNotFound();      // a future date
    dhPage()->call('openDayHistory', $this->kajal->id, '2026-02-30')->assertNotFound();      // not a date

    // Mark Attendance for someone out of reach is refused even if the id is posted directly.
    dhPage($scopedHr)->set('markEmployeeId', (string) $this->saad->id)->set('markDate', '2026-11-03')
        ->set('markCheckIn', '09:00')->set('markCheckOut', '18:00')->set('markReason', 'Biometric failed')
        ->call('submitMarkAttendance')->assertForbidden();

    // A manager may read the log and History but cannot correct a day.
    $managerUser = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $managerUser->id, 'status' => 'active']);
    $this->kajal->update(['manager_id' => $managerUser->id]);
    dhPage($managerUser)->call('openDayHistory', $this->kajal->id, '2026-11-03')
        ->assertSeeHtml('data-day-history=')
        ->assertDontSeeHtml('data-history-correct')
        ->call('openMarkModal', $this->kajal->id, '2026-11-03')->assertForbidden();
});

test('Correct this day opens Mark Attendance filled in with the recorded day', function () {
    dhPage()->call('openDayHistory', $this->saad->id, '2026-11-03')
        ->assertSeeHtml('data-history-correct')
        ->call('openMarkModal', $this->saad->id, '2026-11-03')
        ->assertSet('showMarkModal', true)
        ->assertSet('markEmployeeId', (string) $this->saad->id)
        ->assertSet('markDate', '2026-11-03')
        ->assertSet('markCheckIn', '09:00')
        ->assertSet('markCheckOut', '18:00')
        ->assertSee('Apply correction');

    // Opened from the header it starts empty, on today.
    dhPage()->call('openMarkModal')->assertSet('markEmployeeId', '')->assertSet('markDate', '2026-11-04');
});

test('the register reads a page of rows in one query per kind', function () {
    $rows = Attendance::with('employee.user', 'employee.department', 'employee.shift', 'employee.exitRecord', 'activeBreak')->get();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $lines = app(AttendanceRegister::class)->forAttendances($rows);
    $punchQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'attendance_punches'))->count();
    DB::disableQueryLog();

    expect($lines)->toHaveCount(3)->and($punchQueries)->toBe(1);
});
