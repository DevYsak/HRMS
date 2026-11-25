<?php

use App\Livewire\Attendance\LiveAttendance;
use App\Models\AttendancePunch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Attendance\AttendanceRegister;
use App\Services\Attendance\LiveAttendanceService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The Live Attendance board (Working / Out now / Away, departments, status
 * filters, session clocks, worked against the shift) and its date-wise tabs:
 * By Day (everyone on a date), By Employee (one person over a range) and
 * Punch Audit (exceptions over a range) — all inside the viewer's
 * view_live_attendance scope, all from the canonical attendance services.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-07-14 15:00:00'));   // a Tuesday
    $this->shift = ShiftSetting::create([
        'name' => 'IT Shift', 'code' => 'LB_IT', 'start_time' => '10:30:00', 'end_time' => '19:30:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);
    $this->sales = Department::factory()->create(['name' => 'UK Sales']);
    $this->production = Department::factory()->create(['name' => 'Production']);
    $this->hr = lbUser('hr_admin', $this->sales, 'Hana HR');
});

function lbUser(string $slug, Department $department, string $name): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id, 'name' => $name]);
    Employee::factory()->create([
        'user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active',
        'shift_id' => test()->shift->id, 'joining_date' => '2024-01-01',
    ]);

    return $user->fresh();
}

/** @param array<string, string> $punches time => face|id_card */
function lbPunches(User $user, string $date, array $punches): void
{
    foreach ($punches as $time => $method) {
        AttendancePunch::create(['employee_id' => $user->employee->id, 'punched_at' => "{$date} {$time}", 'punch_date' => $date,
            'method' => $method, 'source' => 'biometric']);
    }
    app(AttendanceDayRebuilder::class)->rebuild($user->employee, Carbon::parse($date));
}

/** @return array<string, list<string>> names per board section */
function lbSections(array $filters = []): array
{
    return collect(app(LiveAttendanceService::class)->snapshot(test()->hr, $filters)['sections'])
        ->map(fn (array $rows) => array_column($rows, 'name'))->all();
}

test('the board puts people in Working, Out now and Away, with the session from their latest IN', function () {
    $wendy = lbUser('employee', $this->sales, 'Wendy Working');
    lbPunches($wendy, '2026-07-14', ['10:25:00' => 'face', '13:00:00' => 'id_card', '13:40:00' => 'face']);
    $omar = lbUser('employee', $this->sales, 'Omar Out');
    lbPunches($omar, '2026-07-14', ['10:20:00' => 'face', '14:10:00' => 'id_card']);
    $bea = lbUser('employee', $this->sales, 'Bea Brief');
    lbPunches($bea, '2026-07-14', ['10:28:00' => 'face', '14:45:00' => 'id_card']);
    lbUser('employee', $this->sales, 'Nora Notin');

    $snapshot = app(LiveAttendanceService::class)->snapshot($this->hr);
    $rows = collect($snapshot['rows'])->keyBy('name');

    expect(lbSections())->toBe([
        'working' => ['Wendy Working'],
        'out' => ['Omar Out', 'Bea Brief'],                 // the longest out first
        'away' => ['Hana HR', 'Nora Notin'],
    ])
        ->and($rows['Wendy Working']['session_since_ms'])->toBe(Carbon::parse('2026-07-14 13:40:00')->getTimestampMs())
        ->and($rows['Wendy Working']['note'])->toContain('In since 01:40 PM')->toContain('first in 10:25 AM')
        ->and($rows['Omar Out']['out_minutes'])->toBe(50)
        ->and($rows['Omar Out']['out_too_long'])->toBeTrue()
        ->and($rows['Omar Out']['note'])->toStartWith('Out for 50m · since 02:10 PM')
        ->and($rows['Bea Brief']['out_too_long'])->toBeFalse()
        ->and($rows['Nora Notin']['note'])->toBe('Not clocked in yet · shift starts 10:30 AM')
        ->and($snapshot['pills'])->toMatchArray(['all' => 5, 'out_too_long' => 1, 'working' => 1, 'out' => 2, 'not_started' => 2]);

    Livewire::actingAs($this->hr)->test(LiveAttendance::class)
        ->assertSeeHtml('data-board-section="working"')
        ->assertSeeHtml('data-board-section="out"')
        ->assertSee('1:20:00')                                // the session, from 13:40
        ->assertSee('Out for 50m')
        ->assertSee('Ends 07:30 PM')
        ->toggle('showAway')
        ->assertDontSee('Nora Notin');
});

test('worked against the shift colours the bar: on track, nearing, done, beyond, way beyond', function () {
    expect(LiveAttendanceService::progressTone(400, 540))->toBe('on_track')
        ->and(LiveAttendanceService::progressTone(470, 540))->toBe('nearing')
        ->and(LiveAttendanceService::progressTone(540, 540))->toBe('done')
        ->and(LiveAttendanceService::progressTone(600, 540))->toBe('beyond')
        ->and(LiveAttendanceService::progressTone(710, 540))->toBe('way_beyond')
        ->and(LiveAttendanceService::initials('Yogesh Sapkal'))->toBe('YS')
        ->and(LiveAttendanceService::initials('Walid'))->toBe('WA');
});

test('department chips count who is working in each department and narrow the board', function () {
    lbPunches(lbUser('employee', $this->sales, 'Wendy Working'), '2026-07-14', ['10:25:00' => 'face']);
    lbPunches(lbUser('employee', $this->production, 'Pat Production'), '2026-07-14', ['10:25:00' => 'face']);
    lbUser('employee', $this->production, 'Quinn Quiet');

    $snapshot = app(LiveAttendanceService::class)->snapshot($this->hr);
    $chips = collect($snapshot['department_counts'])->keyBy('name');

    expect($snapshot['everyone'])->toBe(['working' => 2, 'total' => 4])
        ->and($chips['Production'])->toMatchArray(['working' => 1, 'total' => 2])
        ->and($chips['UK Sales'])->toMatchArray(['working' => 1, 'total' => 2]);

    Livewire::actingAs($this->hr)->test(LiveAttendance::class)
        ->assertSeeHtml('data-department-chip="'.$this->production->id.'"')
        ->call('setDepartment', $this->production->id)
        ->assertSee('Pat Production')
        ->assertDontSee('Wendy Working')
        ->call('setDepartment')
        ->assertSee('Wendy Working');
});

test('a status pill filters the board, and the same pill again clears it', function () {
    lbPunches(lbUser('employee', $this->sales, 'Omar Out'), '2026-07-14', ['10:20:00' => 'face', '14:10:00' => 'id_card']);
    lbPunches(lbUser('employee', $this->sales, 'Bea Brief'), '2026-07-14', ['10:28:00' => 'face', '14:45:00' => 'id_card']);

    Livewire::actingAs($this->hr)->test(LiveAttendance::class)
        ->call('setStatus', 'out_too_long')
        ->assertSet('status', 'out_too_long')
        ->assertSee('Omar Out')
        ->assertDontSee('Bea Brief')
        ->call('setStatus', 'out_too_long')
        ->assertSet('status', '')
        ->assertSee('Bea Brief')
        ->call('setStatus', 'nonsense')
        ->assertSet('status', '');
});

test('Live and Activity refresh at the chosen interval; the date-wise tabs do not', function () {
    Livewire::actingAs($this->hr)->test(LiveAttendance::class)
        ->assertSeeHtml('wire:poll.30s')
        ->set('refresh', 10)
        ->assertSeeHtml('wire:poll.10s')
        ->set('refresh', 0)
        ->assertDontSeeHtml('wire:poll')
        ->set('refresh', 30)
        ->set('tab', 'day')
        ->assertDontSeeHtml('wire:poll');

    // Earlier tab names still open the board.
    Livewire::actingAs($this->hr)->withQueryParams(['tab' => 'overview'])->test(LiveAttendance::class)->assertSet('tab', 'live');
    Livewire::actingAs($this->hr)->withQueryParams(['tab' => 'status'])->test(LiveAttendance::class)->assertSet('tab', 'live');
});

test('By Day shows everyone on a date, punch by punch, with each missed punch marked', function () {
    $wendy = lbUser('employee', $this->sales, 'Wendy Working');
    // Mon 13: Face IN, Face IN again (a missed OUT), Card OUT.
    lbPunches($wendy, '2026-07-13', ['10:45:00' => 'face', '12:00:00' => 'face', '19:35:00' => 'id_card']);
    lbUser('employee', $this->sales, 'Nora Notin');

    $day = app(AttendanceRegister::class)->day($this->hr, Carbon::parse('2026-07-13'));
    $rows = collect($day['rows'])->keyBy('name');

    expect($rows['Wendy Working'])->toMatchArray(['key' => 'present', 'late' => true, 'missed_count' => 1, 'first_in' => '10:45 AM', 'last_out' => '07:35 PM'])
        ->and(array_column($rows['Wendy Working']['trail'], 'state'))->toBe(['counted', 'missed', 'counted', 'counted'])
        ->and($rows['Nora Notin']['key'])->toBe('absent')
        ->and($day['rows'][0]['name'])->toBe('Wendy Working')       // who punched comes first
        ->and($day['counts'])->toMatchArray(['all' => 3, 'present' => 1, 'late' => 1, 'missed' => 1, 'absent' => 2]);

    Livewire::actingAs($this->hr)->test(LiveAttendance::class)
        ->call('openDay', '2026-07-13')
        ->assertSet('tab', 'day')
        ->assertSeeHtml('data-trail-state="missed"')
        ->assertSee('Missed OUT')
        ->assertSee('Nora Notin')
        ->call('setDayStatus', 'missed')
        ->assertSee('Wendy Working')
        ->assertDontSee('Nora Notin')
        ->call('shiftDate', 5)
        ->assertSet('date', '2026-07-14');              // never past today
});

test('By Employee shows one person day by day over a range, with totals — only inside the scope', function () {
    $wendy = lbUser('employee', $this->sales, 'Wendy Working');
    lbPunches($wendy, '2026-07-13', ['10:45:00' => 'face', '12:00:00' => 'face', '19:35:00' => 'id_card']);
    lbPunches($wendy, '2026-07-14', ['10:25:00' => 'face']);
    $pat = lbUser('employee', $this->production, 'Pat Production');

    $register = app(AttendanceRegister::class)->employee($this->hr, $wendy->employee->id, Carbon::parse('2026-07-13'), Carbon::parse('2026-07-14'));

    expect(array_column($register['days'], 'date'))->toBe(['2026-07-14', '2026-07-13'])
        ->and($register['totals'])->toMatchArray(['present' => 2, 'late' => 1, 'missed' => 1]);

    Livewire::actingAs($this->hr)->test(LiveAttendance::class)
        ->call('openEmployee', $wendy->employee->id, '2026-07-13')
        ->assertSet('tab', 'employee')
        ->assertSeeHtml('data-employee-day="2026-07-13"')
        ->assertSee('Wendy Working')
        ->call('preset', 'month')
        ->assertSet('from', '2026-07-01')
        ->assertSet('to', '2026-07-14');

    // A department head of UK Sales cannot open someone in Production.
    $head = lbUser('department_head', $this->sales, 'Nikita Head');
    $this->sales->update(['head_id' => $head->id]);
    expect(app(AttendanceRegister::class)->employee($head, $pat->employee->id, Carbon::parse('2026-07-13'), Carbon::parse('2026-07-14')))->toBeNull();
    Livewire::actingAs($head)->test(LiveAttendance::class)
        ->call('openEmployee', $pat->employee->id)
        ->assertSee('This employee is outside the people you can see.');
});

test('Punch Audit lists every exception over a range and filters by kind', function () {
    $wendy = lbUser('employee', $this->sales, 'Wendy Working');
    lbPunches($wendy, '2026-07-13', ['10:45:00' => 'face', '12:00:00' => 'face', '19:35:00' => 'id_card']);
    lbUser('employee', $this->sales, 'Nora Notin');
    // Ollie never checked out: listed once, as a missing check-out — not again as a missed punch.
    lbPunches(lbUser('employee', $this->sales, 'Ollie Open'), '2026-07-13', ['10:20:00' => 'face']);

    $audit = app(AttendanceRegister::class)->audit($this->hr, Carbon::parse('2026-07-13'), Carbon::parse('2026-07-13'));
    $items = collect($audit['items']);
    $wendyItems = $items->where('name', 'Wendy Working');

    expect($audit['counts'])->toMatchArray(['missed_punch' => 1, 'missing_checkout' => 1, 'late' => 1, 'absent' => 2])
        ->and($wendyItems->pluck('type')->sort()->values()->all())->toBe(['late', 'missed_punch'])
        ->and($wendyItems->firstWhere('type', 'missed_punch')['resolved'])->toBeFalse()
        ->and($items->where('name', 'Ollie Open')->pluck('type')->all())->toBe(['missing_checkout'])
        // The most actionable first: missed punches, then missing check-outs, … absences last.
        ->and($items->pluck('type')->unique()->values()->all())->toBe(['missed_punch', 'missing_checkout', 'late', 'absent']);

    Livewire::actingAs($this->hr)->test(LiveAttendance::class)
        ->set('tab', 'audit')
        ->set('from', '2026-07-13')
        ->set('to', '2026-07-13')
        ->assertSeeHtml('data-audit-item="missed_punch"')
        ->call('setAuditType', 'late')
        ->assertSeeHtml('data-audit-item="late"')
        ->assertDontSeeHtml('data-audit-item="missed_punch"')
        ->assertDontSeeHtml('data-audit-item="absent"');
});

test('a range never runs past today and is capped in length', function () {
    [$from, $to] = AttendanceRegister::range(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), AttendanceRegister::MAX_AUDIT_DAYS);

    expect($to->toDateString())->toBe('2026-07-14')
        ->and($from->toDateString())->toBe('2026-06-14');

    [$from, $to] = AttendanceRegister::range(Carbon::parse('2026-07-20'), Carbon::parse('2026-07-10'), 31);
    expect($from->toDateString())->toBe('2026-07-10')->and($to->toDateString())->toBe('2026-07-10');
});
