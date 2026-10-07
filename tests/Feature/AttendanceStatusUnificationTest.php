<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\TeamAttendance;
use App\Livewire\ManagerDashboard;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceStatusResolver as S;
use App\Services\EmployeeDashboardService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * One status for every screen — working, on break, completed, missing
 * checkout or not in — from the PunchTimeline + AttendanceCalculator, never
 * from "check_out is empty". Working only inside the shift window; missing
 * checkout after shift end + 1h; completed on a valid final ID Card OUT.
 *
 * Day shift 09:00–18:00 (cutoff 19:00). Wednesday 14 October 2026.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 14:00:00'));
    $this->dayShift = ShiftSetting::create([
        'name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9,
    ]);
    $this->nightShift = ShiftSetting::create([
        'name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 8, 'ot_threshold_hours' => 8,
    ]);
});

function sunEmployee(?ShiftSetting $shift = null, array $extra = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create($extra + [
        'user_id' => $user->id, 'status' => 'active', 'shift_id' => ($shift ?? test()->dayShift)->id,
        'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

/** @param  array<int, array{0: string, 1: string, 2?: ?string}>  $punches  [datetime|time, method, device direction] */
function sunPunches(Employee $employee, array $punches, string $date = '2026-10-14'): void
{
    foreach ($punches as $p) {
        AttendancePunch::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => strlen($p[0]) > 8 ? $p[0] : "$date {$p[0]}",
            'punch_date' => $date, 'method' => $p[1], 'direction' => $p[2] ?? null, 'source' => 'biometric',
        ]);
    }
}

function sunState(Employee $employee, string $date = '2026-10-14'): array
{
    return app(S::class)->resolve($employee, $date);
}

test('last valid punch Face IN during the shift is Working', function () {
    $e = sunEmployee();
    sunPunches($e, [['09:05:00', 'face']]);

    expect(sunState($e)['state'])->toBe(S::WORKING)
        ->and(sunState($e)['live'])->toBeTrue();
});

test('the same employee after shift end + 1h with no OUT is Missing Checkout, not live', function () {
    $e = sunEmployee();
    sunPunches($e, [['09:05:00', 'face']]);

    $this->travelTo(Carbon::parse('2026-10-14 18:59:00'));
    expect(sunState($e)['state'])->toBe(S::WORKING);

    $this->travelTo(Carbon::parse('2026-10-14 19:01:00'));
    $status = sunState($e);

    expect($status['state'])->toBe(S::MISSING_CHECKOUT)
        ->and($status['live'])->toBeFalse()
        ->and($status['worked_minutes'])->toBe(0);   // nothing keeps growing past the cutoff
});

test('a valid final ID Card OUT is Completed', function () {
    $e = sunEmployee();
    sunPunches($e, [['09:05:00', 'face'], ['17:30:00', 'id_card']]);

    expect(sunState($e)['state'])->toBe(S::COMPLETED)
        ->and(sunState($e)['live'])->toBeFalse();

    $this->travelTo(Carbon::parse('2026-10-14 22:00:00'));
    expect(sunState($e)['state'])->toBe(S::COMPLETED);   // never turns into missing later
});

test('a running break shows On Break', function () {
    $e = sunEmployee();
    sunPunches($e, [['09:05:00', 'face']]);
    $row = Attendance::create(['employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:05:00', 'status' => 'on_time', 'work_mode' => 'office']);
    $row->breakLogs()->create(['employee_id' => $e->id, 'break_start' => '2026-10-14 13:30:00']);

    expect(app(S::class)->resolve($e, '2026-10-14', $row->load('activeBreak'))['state'])->toBe(S::ON_BREAK);
});

test('a weekly off with no punches is Not in, never a missing checkout', function () {
    $this->travelTo(Carbon::parse('2026-10-17 20:00:00'));   // Saturday
    $e = sunEmployee();

    $status = sunState($e, '2026-10-17');

    expect($status['state'])->toBe(S::NOT_IN)->and($status['reason'])->toBe('weekly_off');
});

test('a real session worked on a weekly off still needs its OUT', function () {
    $this->travelTo(Carbon::parse('2026-10-17 20:00:00'));
    $e = sunEmployee();
    sunPunches($e, [['09:30:00', 'face']], '2026-10-17');

    expect(sunState($e, '2026-10-17')['state'])->toBe(S::MISSING_CHECKOUT);
});

test('a public holiday with no punches is Not in, never a missing checkout', function () {
    PublicHoliday::create(['name' => 'Founders Day', 'date' => '2026-10-15', 'country' => 'UK']);
    $this->travelTo(Carbon::parse('2026-10-15 20:00:00'));
    $e = sunEmployee();

    $status = sunState($e, '2026-10-15');

    expect($status['state'])->toBe(S::NOT_IN)->and($status['reason'])->toBe('holiday');
});

test('an overnight shift stays Working across midnight and goes Missing at its own cutoff', function () {
    $e = sunEmployee($this->nightShift);
    sunPunches($e, [['22:05:00', 'face']], '2026-10-13');

    $this->travelTo(Carbon::parse('2026-10-14 02:00:00'));
    $working = app(S::class)->current($e);
    expect($working['state'])->toBe(S::WORKING)
        ->and($working['work_date'])->toBe('2026-10-13');

    $this->travelTo(Carbon::parse('2026-10-14 06:59:00'));   // before shift end + 1h
    expect(app(S::class)->current($e)['state'])->toBe(S::WORKING);

    $this->travelTo(Carbon::parse('2026-10-14 07:30:00'));
    expect(sunState($e, '2026-10-13')['state'])->toBe(S::MISSING_CHECKOUT);
});

test('an overnight shift closed by a Card OUT after midnight is Completed with the real span', function () {
    $e = sunEmployee($this->nightShift);
    sunPunches($e, [['2026-10-13 22:05:00', 'face'], ['2026-10-14 05:55:00', 'id_card']], '2026-10-13');

    $this->travelTo(Carbon::parse('2026-10-14 06:30:00'));
    $status = sunState($e, '2026-10-13');

    expect($status['state'])->toBe(S::COMPLETED)
        ->and($status['worked_minutes'])->toBe(470);   // 22:05 → 05:55
});

test('a regularised day follows its corrected timeline', function () {
    $e = sunEmployee();
    sunPunches($e, [['09:30:00', 'face']]);   // raw: a lone IN
    Attendance::create([
        'employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:00:00', 'check_out' => '2026-10-14 17:30:00',
        'original_check_in' => '2026-10-14 09:30:00', 'is_regularized' => true, 'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 8.5,
    ]);
    $this->travelTo(Carbon::parse('2026-10-14 21:00:00'));

    $status = app(S::class)->forAttendance(Attendance::first());

    expect($status['state'])->toBe(S::COMPLETED)
        ->and($status['worked_minutes'])->toBe(510)
        ->and($status['day']->regularised)->toBeTrue();
});

test('a duplicate Face burst is one IN and still Working', function () {
    $e = sunEmployee();
    sunPunches($e, [['09:05:01', 'face'], ['09:05:22', 'face'], ['09:05:48', 'face']]);

    $status = sunState($e);

    expect($status['state'])->toBe(S::WORKING)
        ->and($status['first_in']->format('H:i:s'))->toBe('09:05:48');
});

test('a stray Card OUT before any Face IN decides nothing', function () {
    $e = sunEmployee();
    sunPunches($e, [['08:50:00', 'id_card']]);

    expect(sunState($e)['state'])->toBe(S::NOT_IN);

    sunPunches($e, [['09:05:00', 'face']]);
    expect(sunState($e)['state'])->toBe(S::WORKING);
});

test('a Face punch the device tagged OUT is still a Working IN', function () {
    $e = sunEmployee();
    sunPunches($e, [['09:05:00', 'face', 'out']]);

    expect(sunState($e)['state'])->toBe(S::WORKING);
});

test('a punch stamped on another day cannot close or extend today', function () {
    $e = sunEmployee();
    sunPunches($e, [['2026-10-14 09:05:00', 'face'], ['2026-10-15 05:00:00', 'id_card']], '2026-10-14');

    expect(sunState($e)['state'])->toBe(S::WORKING);

    $this->travelTo(Carbon::parse('2026-10-14 20:00:00'));
    expect(sunState($e)['state'])->toBe(S::MISSING_CHECKOUT);
});

test('Employee Dashboard shows Working, then Missing checkout, then Completed', function () {
    $e = sunEmployee();
    sunPunches($e, [['09:05:00', 'face']]);

    $today = fn () => app(EmployeeDashboardService::class)->build($e->user->fresh())['today'];

    expect($today())->toMatchArray(['working' => true, 'missing_checkout' => false, 'clocked_out' => false]);

    $this->travelTo(Carbon::parse('2026-10-14 19:30:00'));
    expect($today())->toMatchArray(['working' => false, 'missing_checkout' => true, 'state' => 'missing_checkout', 'live_base' => null]);

    sunPunches($e, [['18:00:00', 'id_card']]);
    expect($today())->toMatchArray(['working' => false, 'missing_checkout' => false, 'clocked_out' => true, 'check_out' => '6:00 PM']);
});

test('Manager Dashboard marks LIVE inside the shift and MISSING CHECKOUT after the cutoff', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'manager_id' => null]);
    $e = sunEmployee(extra: ['manager_id' => $manager->id]);
    sunPunches($e, [['09:05:00', 'face']]);
    Attendance::create(['employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:05:00', 'status' => 'on_time', 'work_mode' => 'office']);

    Livewire::actingAs($manager)->test(ManagerDashboard::class)
        ->assertSeeHtml('data-attendance-state="working"')
        ->assertDontSee('MISSING CHECKOUT');

    $this->travelTo(Carbon::parse('2026-10-14 19:30:00'));

    Livewire::actingAs($manager)->test(ManagerDashboard::class)
        ->assertSeeHtml('data-attendance-state="missing_checkout"')
        ->assertSee('MISSING CHECKOUT')
        ->assertDontSeeHtml('data-attendance-state="working"');
});

test('Team Attendance counts only people inside their window as working', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'manager_id' => null]);
    $inside = sunEmployee(extra: ['manager_id' => $manager->id]);
    $forgot = sunEmployee(extra: ['manager_id' => $manager->id]);
    $done = sunEmployee(extra: ['manager_id' => $manager->id]);
    sunPunches($inside, [['09:05:00', 'face']]);
    sunPunches($forgot, [['09:05:00', 'face']]);
    sunPunches($done, [['09:05:00', 'face'], ['17:00:00', 'id_card']]);
    // The raw rows say nobody has a check-out except the completed one — but
    // the board reads the shared status, not the column.
    foreach ([$inside, $forgot] as $emp) {
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:05:00', 'status' => 'on_time', 'work_mode' => 'office']);
    }

    $this->travelTo(Carbon::parse('2026-10-14 18:30:00'));
    Livewire::actingAs($manager)->test(TeamAttendance::class)
        ->assertViewHas('boardStats', fn ($s) => $s['working'] === 2 && $s['missing_checkout'] === 0)
        ->assertViewHas('currentlyIn', fn ($c) => $c->count() === 2);

    $this->travelTo(Carbon::parse('2026-10-14 19:30:00'));
    Livewire::actingAs($manager)->test(TeamAttendance::class)
        ->assertViewHas('boardStats', fn ($s) => $s['working'] === 0 && $s['missing_checkout'] === 2)
        ->assertViewHas('currentlyIn', fn ($c) => $c->isEmpty())
        ->assertSee('Missing Checkout');
});

test('All Attendance badges LIVE, then missing, then the real OUT time', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $e = sunEmployee();
    sunPunches($e, [['09:05:00', 'face']]);
    $row = Attendance::create(['employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:05:00', 'status' => 'on_time', 'work_mode' => 'office']);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertViewHas('rowStatus', fn ($rows) => $rows[$row->id]['state'] === S::WORKING)
        ->assertSeeHtml('data-attendance-state="working"');

    $this->travelTo(Carbon::parse('2026-10-14 19:30:00'));
    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertViewHas('rowStatus', fn ($rows) => $rows[$row->id]['state'] === S::MISSING_CHECKOUT)
        ->assertSeeHtml('data-attendance-state="missing_checkout"')
        ->assertDontSeeHtml('data-attendance-state="working"');

    // A valid Card OUT the row never recorded: the timeline decides.
    sunPunches($e, [['17:45:00', 'id_card']]);
    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertViewHas('rowStatus', fn ($rows) => $rows[$row->id]['state'] === S::COMPLETED)
        ->assertSeeHtml('data-attendance-state="completed"');
});

test('the employee drawer status follows the same resolver', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $e = sunEmployee();
    sunPunches($e, [['09:05:00', 'face']]);

    $this->travelTo(Carbon::parse('2026-10-14 19:30:00'));

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $e->id)
        ->assertSet('drawer.status', 'Missing Checkout');
});
