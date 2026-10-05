<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\Employees\EmployeeCreate;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\TimeOff\EmployeeLeaveDetail;
use App\Livewire\TimeOff\LeaveManagement;
use App\Livewire\TimeOff\MyLeaveBalances;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\DecemberMandatoryDay;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\JobTitle;
use App\Models\LeaveEncashment;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\SalaryCycle;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\WorkMode;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\LeaveManagementService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Every screen shows the one figure, and HR's screens show the Conexus model.
 *
 * Yogesh's register row (2 + 7 − 5 = 4) is the worked example: the dashboard
 * KPI and card, My Time Off, My Balances, HR Leave Management and HR's
 * Employee Leave Detail must all read 4, and none may show 28-day Annual Leave.
 *
 * Clock: 5 October 2026 (leave year 2026/27).
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    foreach (range(26, 31) as $day) {
        DecemberMandatoryDay::create(['year' => 2026, 'date' => "2026-12-{$day}", 'description' => 'Company shutdown']);
    }
    // The CSL is production's existing Paid Leave type, renamed in place.
    conexusPaidLeave();
    app(ConexusLeavePolicyService::class)->apply();
});

function csEmployee(array $register, array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee, 'name' => $register['name'] ?? 'Yogesh Sakpal']);
    $employee = Employee::factory()->create($attributes + ['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08']);

    app(LeaveRegisterReconciliationService::class)->reconcileEmployee(
        $employee->fresh(),
        $register + ['name' => 'Yogesh', 'emails' => [$user->email], 'encashed' => 0, 'available' => $register['credit'] + $register['carry'] - $register['used']],
        LeaveYear::where('label', '2026/27')->first(),
        LeaveType::where('code', 'CSL')->firstOrFail(),
        LeaveType::withTrashed()->where('code', 'AL')->first(),
        null,
    );

    return $employee->fresh();
}

function csHr(): User
{
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $hr->id, 'status' => 'active']);

    return $hr;
}

// ── One figure everywhere ───────────────────────────────────────────────────

test('every screen shows Yogesh 4 days and none shows a 28-day Annual Leave', function () {
    $yogesh = csEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);
    $year = LeaveYear::where('label', '2026/27')->first();
    $csl = LeaveType::where('code', 'CSL')->first();

    $dashboard = Livewire::actingAs($yogesh->user)->test(Dashboard::class)->assertOk()->assertDontSee('Annual Leave');
    $dashboardKpi = collect($dashboard->viewData('kpis'))->firstWhere('label', 'Available Leave')['value'];
    $dashboardCard = $dashboard->viewData('leave')['primary']['available'];

    $myTimeOff = Livewire::actingAs($yogesh->user)->test(MyTimeOff::class)->assertOk()->assertDontSee('Annual Leave');
    $balances = Livewire::actingAs($yogesh->user)->test(MyLeaveBalances::class)->assertOk()->assertDontSee('Annual Leave')->assertSee('Apply CSL');

    $hrRow = app(LeaveManagementService::class)->rows($year, $csl)->firstWhere('employee_id', $yogesh->id);
    $hrDetail = app(LeaveManagementService::class)->employeeDetail($yogesh, $year)
        ->first(fn ($r) => $r['leave_type']?->code === 'CSL')['summary']['approved_available'];

    expect($dashboardKpi)->toBe('4 days')
        ->and($dashboardCard)->toBe(4.0)
        ->and($myTimeOff->viewData('overview')['available_leave'])->toBe(4.0)
        ->and($balances->get('overview')['csl']['summary']['approved_available'])->toBe(4.0)
        ->and($hrRow['available'])->toBe(4.0)
        ->and($hrDetail)->toBe(4.0);
});

test('Mayuresh reads 1 everywhere', function () {
    $mayuresh = csEmployee(['name' => 'Mayuresh', 'credit' => 2, 'carry' => 0, 'used' => 1]);

    $dashboard = Livewire::actingAs($mayuresh->user)->test(Dashboard::class);

    expect(collect($dashboard->viewData('kpis'))->firstWhere('label', 'Available Leave')['value'])->toBe('1 day')
        ->and(Livewire::actingAs($mayuresh->user)->test(MyTimeOff::class)->viewData('overview')['available_leave'])->toBe(1.0);
});

test('Comp Off counts toward Available Leave; MDL never does', function () {
    $employee = csEmployee(['credit' => 2, 'carry' => 0, 'used' => 0]);
    app(LeaveService::class)->creditCompOff($employee, Carbon::parse('2026-12-28'));

    $overview = Livewire::actingAs($employee->user)->test(MyTimeOff::class)->viewData('overview');

    expect($overview['available_leave'])->toBe(3.0)
        ->and($overview['mdl']['configured'])->toBe(6)
        ->and($overview['mdl']['remaining'])->toBe(6);
});

test('My Balances shows the CSL, MDL and Comp Off cards and no Annual Leave', function () {
    $employee = csEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);

    Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)
        ->assertSee('Casual / Sick Leave')
        ->assertSee('Policy: 12 days / year · 1 day per completed month')
        ->assertSee('Accrued this year')
        ->assertSee('No expiry · never lapses')
        ->assertSee('Mandatory December Leave')
        ->assertSee('6 mandatory company shutdown days')
        ->assertSee('Sat, 26 Dec 2026')
        ->assertSee('Comp Off')
        ->assertDontSee('Annual Leave')
        ->assertDontSee('Total Leave Allocated');
});

test('the month-wise statement shows register usage as reconciliation, not as leave taken in October', function () {
    $employee = csEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);

    $months = Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)
        ->call('showStatement', LeaveType::where('code', 'CSL')->value('id'))
        ->assertSee('Other / reconciliation')
        ->get('statement');

    $july = $months->firstWhere('month', '2026-07');
    $october = $months->firstWhere('month', '2026-10');

    expect($july['opening'])->toBe(0.0)
        ->and($july['current_credits'])->toBe(2.0)
        ->and($july['carry_forward'])->toBe(7.0)
        ->and($october['used'])->toBe(0.0)
        ->and($october['other'])->toBe(-5.0)
        ->and($october['closing'])->toBe(4.0);
});

// ── HR Leave Management ─────────────────────────────────────────────────────

test('HR Leave Management opens on CSL with the Conexus columns and warnings', function () {
    $shradha = csEmployee(['name' => 'Shradha', 'credit' => 2, 'carry' => 0, 'used' => 3]);

    Livewire::actingAs(csHr())->test(LeaveManagement::class)
        ->assertOk()
        ->assertSet('leaveTypeId', LeaveType::where('code', 'CSL')->value('id'))
        ->assertSee('Current-year credit')
        ->assertSee('Encashed')
        ->assertSee('Comp Off')
        ->assertSee('Warnings')
        ->assertSee('Negative balance')
        ->assertSee('6/6 dates');

    $row = app(LeaveManagementService::class)->rows(LeaveYear::where('label', '2026/27')->first(), LeaveType::where('code', 'CSL')->first())
        ->firstWhere('employee_id', $shradha->id);

    expect($row['available'])->toBe(-1.0)
        ->and($row['credit'])->toBe(2.0)
        ->and($row['flags']['negative'])->toBeTrue();
});

test('HR can filter Leave Management to incomplete HR profiles', function () {
    $complete = csEmployee(['credit' => 2, 'carry' => 0, 'used' => 0], [
        'department_id' => Department::factory()->create()->id, 'job_title_id' => JobTitle::factory()->create()->id,
        'manager_id' => User::factory()->create()->id, 'shift_id' => ShiftSetting::create(['name' => 'IT', 'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 5])->id,
        'employment_type_id' => EmploymentType::create(['name' => 'Permanent', 'slug' => 'permanent-cs'])->id,
        'work_mode_id' => WorkMode::create(['name' => 'Office', 'slug' => 'office-cs'])->id,
        'salary_cycle_id' => SalaryCycle::create(['name' => 'Cycle A', 'slug' => 'cycle-a-cs', 'start_day' => 1, 'end_day' => 31, 'pay_day' => 1])->id,
    ]);
    $incomplete = csEmployee(['credit' => 2, 'carry' => 0, 'used' => 0], ['department_id' => null, 'employment_type_id' => null]);

    expect($complete->missingHrFields())->toBe([])
        ->and($incomplete->missingHrFields())->toHaveKeys(['department_id', 'employment_type_id']);

    $rows = app(LeaveManagementService::class)->rows(LeaveYear::where('label', '2026/27')->first(), LeaveType::where('code', 'CSL')->first(), ['flag' => 'incomplete_profile']);

    expect($rows->pluck('employee_id'))->toContain($incomplete->id)->not->toContain($complete->id);
});

test('HR sees an employee\'s encashment history', function () {
    $employee = csEmployee(['credit' => 2, 'carry' => 7, 'used' => 0]);
    LeaveEncashment::create(['employee_id' => $employee->id, 'leave_type_id' => LeaveType::where('code', 'CSL')->value('id'), 'requested_days' => 2, 'status' => 'pending', 'payout_month' => '2026-10', 'source_leave_year' => 2026]);

    Livewire::actingAs(csHr())->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->set('tab', 'encashments')
        ->assertSee('Payout month')
        ->assertSee('2026-10');
});

// ── Employee Management ─────────────────────────────────────────────────────

test('the HR completion queue lists employees with missing employment data', function () {
    $incomplete = csEmployee(['credit' => 2, 'carry' => 0, 'used' => 0], ['department_id' => null]);

    Livewire::actingAs(csHr())->test(EmployeeIndex::class)
        ->set('incomplete', true)
        ->assertSee($incomplete->user->name)
        ->assertSee('Incomplete HR profile');
});

test('a new employee is saved without an invented joining date', function () {
    $hr = User::factory()->create(['role' => UserRole::SuperAdmin]);

    Livewire::actingAs($hr)->test(EmployeeCreate::class)
        ->assertSet('joining_date', '')
        ->set('name', 'No Date Yet')
        ->set('email', 'no.date@conexus-ns.com')
        ->set('employee_id', 'CNX-9100')
        ->set('roleId', (string) Role::where('slug', 'employee')->value('id'))
        ->set('status', 'onboarding')
        ->call('save')
        ->assertHasNoErrors(['joining_date']);

    $employee = User::where('email', 'no.date@conexus-ns.com')->first()?->employee;

    expect($employee)->not->toBeNull()
        ->and($employee->joining_date)->toBeNull()
        ->and($employee->missingHrFields())->toHaveKey('joining_date');
});

test('a manager sees their own reports in Employee Management', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'status' => 'active']);
    $report = Employee::factory()->create(['user_id' => User::factory()->create(['name' => 'Direct Report Person'])->id, 'manager_id' => $manager->id, 'status' => 'active']);
    Employee::factory()->create(['user_id' => User::factory()->create(['name' => 'Someone Elses Report'])->id, 'manager_id' => User::factory()->create()->id, 'status' => 'active']);

    Livewire::actingAs($manager)->test(EmployeeIndex::class)
        ->assertSee('Direct Report Person')
        ->assertDontSee('Someone Elses Report');

    expect($manager->can('view', $report))->toBeTrue();
});
