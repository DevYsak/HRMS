<?php

use App\Enums\UserRole;
use App\Livewire\Profile\MyProfile;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveYearResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Reading a leave balance on the right side of 1 January.
 *
 * The leave year runs 1 July to 30 June, so its integer and the calendar year
 * disagree for exactly half of every year. Three screens read balances with
 * now()->year, which silently returned nothing from 1 January to 30 June — an
 * approver deciding leave in March was shown no balance at all, and a profile
 * reported zero days available to someone who had plenty.
 *
 * The dates below are the four corners of that window.
 */
function lybrYears(): array
{
    $y2526 = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $y2627 = LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
    $y2728 = LeaveYear::firstOrCreate(['label' => '2027/28'], ['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30']);

    return [$y2526, $y2627, $y2728];
}

function lybrType(): LeaveType
{
    return LeaveType::firstOrCreate(['code' => 'LYBR'], [
        'name' => 'Boundary Annual', 'category' => 'annual', 'allow_paid_request' => true,
    ]);
}

function lybrEmployee(?User $manager = null): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'manager_id' => $manager?->id,
        'status' => 'active',
    ]);
}

function lybrHr(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'hr_admin')->firstOrFail();

    return User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);
}

function lybrBalance(Employee $e, LeaveType $t, LeaveYear $y, float $allocated): LeaveBalance
{
    return LeaveBalance::updateOrCreate(
        ['employee_id' => $e->id, 'leave_type_id' => $t->id, 'year' => $y->legacyYear()],
        ['leave_year_id' => $y->id, 'allocated_days' => $allocated, 'used_days' => 0],
    );
}

// ── The four corners of the window ─────────────────────────────────────────

test('the resolver maps each boundary date to the right leave year', function (string $date, string $expected) {
    lybrYears();
    test()->travelTo(Carbon::parse($date.' 12:00'));

    expect(app(LeaveYearResolver::class)->current()->label)->toBe($expected);
})->with([
    'new year eve is still 2026/27' => ['2026-12-31', '2026/27'],
    // The calendar year rolls; the leave year does not.
    'new year day is still 2026/27' => ['2027-01-01', '2026/27'],
    'the last day of the leave year' => ['2027-06-30', '2026/27'],
    'the first day of the next one' => ['2027-07-01', '2027/28'],
]);

test('the legacy integer follows the leave year, not the calendar', function () {
    [, $y2627, $y2728] = lybrYears();

    test()->travelTo(Carbon::parse('2026-12-31 12:00'));
    expect(app(LeaveYearResolver::class)->legacyYearFor())->toBe($y2627->legacyYear());

    // The moment that used to break every balance read.
    test()->travelTo(Carbon::parse('2027-01-01 12:00'));
    expect(app(LeaveYearResolver::class)->legacyYearFor())->toBe($y2627->legacyYear());

    test()->travelTo(Carbon::parse('2027-06-30 12:00'));
    expect(app(LeaveYearResolver::class)->legacyYearFor())->toBe($y2627->legacyYear());

    test()->travelTo(Carbon::parse('2027-07-01 12:00'));
    expect(app(LeaveYearResolver::class)->legacyYearFor())->toBe($y2728->legacyYear());
});

// ── The three screens, read inside the January–June window ─────────────────

test('the profile shows the leave balance in January, not zero', function () {
    [, $y2627] = lybrYears();
    $type = lybrType();
    $employee = lybrEmployee();
    lybrBalance($employee, $type, $y2627, 28);

    // Under the old read this returned no rows at all, so the profile
    // reported 0 days available to somebody holding 28.
    test()->travelTo(Carbon::parse('2027-01-15 12:00'));

    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->assertOk()
        ->assertViewHas('kpis', fn (array $s) => $s['leave'] > 0);
});

test('the profile reports the same balance either side of 1 January', function () {
    [, $y2627] = lybrYears();
    $type = lybrType();
    $employee = lybrEmployee();
    lybrBalance($employee, $type, $y2627, 28);

    test()->travelTo(Carbon::parse('2026-12-31 12:00'));
    $before = null;
    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->assertViewHas('kpis', function (array $s) use (&$before) {
            $before = $s['leave'];

            return true;
        });

    test()->travelTo(Carbon::parse('2027-01-01 12:00'));
    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->assertViewHas('kpis', fn (array $s) => $s['leave'] === $before);
});

test('the HR queue shows an approver the balance in March', function () {
    [, $y2627] = lybrYears();
    $type = lybrType();
    $hr = lybrHr();
    $employee = lybrEmployee();
    lybrBalance($employee, $type, $y2627, 28);

    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => '2027-03-10', 'end_date' => '2027-03-11', 'days' => 2,
        'reason' => 'Boundary window', 'status' => 'pending', 'requested_leave_status' => 'paid',
    ]);

    test()->travelTo(Carbon::parse('2027-03-01 12:00'));

    Livewire::actingAs($hr)->test(AllTimeOff::class)
        ->assertOk()
        ->assertViewHas('requests', function ($requests) {
            $req = collect(method_exists($requests, 'items') ? $requests->items() : $requests)->first();

            // An approver must not be asked to decide against a blank balance.
            return $req !== null && $req->availableBalance !== null;
        });
});

test('the team queue shows a manager the balance in March', function () {
    [, $y2627] = lybrYears();
    $type = lybrType();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $employee = lybrEmployee($manager);
    lybrBalance($employee, $type, $y2627, 28);

    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => '2027-03-10', 'end_date' => '2027-03-11', 'days' => 2,
        'reason' => 'Boundary window', 'status' => 'pending', 'requested_leave_status' => 'paid',
    ]);

    test()->travelTo(Carbon::parse('2027-03-01 12:00'));

    Livewire::actingAs($manager)->test(TeamTimeOff::class)
        ->assertOk()
        ->assertViewHas('pendingRequests', function ($requests) {
            $req = collect(method_exists($requests, 'items') ? $requests->items() : $requests)->first();

            return $req !== null && $req->availableBalance !== null;
        });
});

// ── Behaviour outside the window is unchanged ─────────────────────────────

test('a reading in August is unaffected, where the two years already agreed', function () {
    [, $y2627] = lybrYears();
    $type = lybrType();
    $employee = lybrEmployee();
    lybrBalance($employee, $type, $y2627, 28);

    // August 2026: calendar year and leave-year integer are both 2026, so the
    // old read happened to be right here. It must stay right.
    test()->travelTo(Carbon::parse('2026-08-15 12:00'));

    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->assertOk()
        ->assertViewHas('kpis', fn (array $s) => $s['leave'] > 0);
});

test('crossing into the next leave year reads the next year, not the closed one', function () {
    [, $y2627, $y2728] = lybrYears();
    $type = lybrType();
    $employee = lybrEmployee();

    lybrBalance($employee, $type, $y2627, 28);
    lybrBalance($employee, $type, $y2728, 5);

    test()->travelTo(Carbon::parse('2027-07-01 12:00'));

    // 1 July starts a new year: the fresh 5, not the closed year's 28.
    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->assertOk()
        ->assertViewHas('kpis', fn (array $s) => $s['leave'] === 5.0);
});

// ── Nothing was written ────────────────────────────────────────────────────

test('reading a balance across the boundary changes no stored figure', function () {
    [$y2526, $y2627] = lybrYears();
    $type = lybrType();
    $employee = lybrEmployee();

    $historical = lybrBalance($employee, $type, $y2526, 10);
    lybrBalance($employee, $type, $y2627, 28);

    // Both sides read from the database: the in-memory model never had
    // carried_forward_days assigned, so comparing it to a fresh row would
    // report a difference the read never caused.
    $before = $historical->fresh()->only(['allocated_days', 'used_days', 'carried_forward_days', 'year']);

    test()->travelTo(Carbon::parse('2027-02-01 12:00'));
    Livewire::actingAs($employee->user)->test(MyProfile::class)->assertOk();

    expect($historical->fresh()->only(['allocated_days', 'used_days', 'carried_forward_days', 'year']))
        ->toBe($before);
});
