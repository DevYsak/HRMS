<?php

use App\Enums\UserRole;
use App\Exceptions\ApprovalNotPermitted;
use App\Livewire\Dashboard;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\ManagerDashboard;
use App\Livewire\Onboarding\OffboardingManager;
use App\Livewire\Onboarding\OnboardingChecklist;
use App\Livewire\Profile\EmployeeProfile;
use App\Models\Attendance;
use App\Models\DecemberMandatoryDay;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExitRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\EmployeeDashboardService;
use App\Services\LeaveBalanceService;
use App\Services\ProbationEngine;
use App\Services\Profile\ProfileChangeService;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Spec v3.1 §4 role behaviour: Department Heads see their department, HR
 * administers employees, nobody decides their own record, and an employee
 * sees only their own self-service data (plus names + dates of colleagues
 * on leave). Every rule is enforced on the server, not by hiding a button.
 */
function scopeUser(UserRole $role, array $employee = [], array $user = []): User
{
    $account = User::factory()->create(array_merge(['role' => $role], $user));
    Employee::factory()->create(array_merge(['user_id' => $account->id, 'status' => 'active', 'manager_id' => null], $employee));

    return $account->fresh();
}

beforeEach(fn () => Notification::fake());

// ── Department Head scope ───────────────────────────────────────────────────

test('a Director can be scoped to a department and then edits only that department', function () {
    $uk = Department::factory()->create(['name' => 'UK Sales']);
    $it = Department::factory()->create(['name' => 'IT']);
    $director = scopeUser(UserRole::Director, ['department_id' => $uk->id], ['scope_departments' => [$uk->id]]);
    $inside = scopeUser(UserRole::Employee, ['department_id' => $uk->id]);
    $outside = scopeUser(UserRole::Employee, ['department_id' => $it->id]);

    Livewire::actingAs($director)->test(EmployeeEdit::class, ['employee' => $inside->employee])->assertOk();
    Livewire::actingAs($director)->test(EmployeeEdit::class, ['employee' => $outside->employee])->assertForbidden();
});

test('HR can save a department scope on a Director (it used to be dropped)', function () {
    $hr = scopeUser(UserRole::HrAdmin);
    $uk = Department::factory()->create(['name' => 'UK Sales']);
    $director = scopeUser(UserRole::Director);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $director->employee])
        ->set('scopeDepartments', [$uk->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($director->fresh()->scope_departments)->toBe([$uk->id]);
});

test('a scoped Director lands on a department team dashboard, not the company view', function () {
    $uk = Department::factory()->create(['name' => 'UK Sales']);
    $director = scopeUser(UserRole::Director, ['department_id' => $uk->id], ['scope_departments' => [$uk->id]]);
    scopeUser(UserRole::Employee, ['department_id' => $uk->id]);

    Livewire::actingAs($director)->test(Dashboard::class)
        ->assertRedirect(route('dashboard.manager'));
    Livewire::actingAs($director)->test(ManagerDashboard::class)->assertOk();
});

test('a manager\'s dashboard shows their own reporting line, never another team', function () {
    $manager = scopeUser(UserRole::Manager);
    $mine = scopeUser(UserRole::Employee, ['manager_id' => $manager->id]);
    $other = scopeUser(UserRole::Employee);

    Livewire::actingAs($manager)->test(ManagerDashboard::class)
        ->assertSee($mine->name)
        ->assertDontSee($other->name);
});

// ── Records above you and your own record ───────────────────────────────────

test('HR cannot edit the Super Admin\'s record (its login email lives there)', function () {
    $hr = scopeUser(UserRole::HrAdmin);
    $superAdmin = scopeUser(UserRole::SuperAdmin);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $superAdmin->employee])->assertForbidden();
});

test('HR cannot approve a change to their own bank account', function () {
    $hr = scopeUser(UserRole::HrAdmin);
    $request = ProfileChangeRequest::create([
        'employee_id' => $hr->employee->id, 'requested_by' => $hr->id, 'field' => 'account_number',
        'old_value' => null, 'new_value' => '12345678901', 'status' => ProfileChangeRequest::STATUS_PENDING,
    ]);

    expect(fn () => app(ProfileChangeService::class)->approve($request, $hr))->toThrow(ApprovalNotPermitted::class);
    expect($request->fresh()->status)->toBe(ProfileChangeRequest::STATUS_PENDING);
});

test('the financial tab (bank, PAN, Aadhaar) is not shown to a Director', function () {
    $director = scopeUser(UserRole::Director);
    $employee = scopeUser(UserRole::Employee);

    Livewire::actingAs($director)->test(EmployeeProfile::class, ['employee' => $employee->employee])
        ->call('setTab', 'financial')
        ->assertSet('activeTab', 'overview')
        ->call('editField', 'account_number')
        ->assertForbidden();
});

test('nobody changes their own leave balance', function () {
    $hr = scopeUser(UserRole::HrAdmin);
    $type = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true]);

    expect(fn () => app(LeaveBalanceService::class)->adjust($hr->employee, $type, 'credit', 5, 'Bonus days', 'self', $hr))
        ->toThrow(DomainException::class, 'your own leave balance');
});

test('a manager outside the employee\'s line cannot confirm their probation', function () {
    $lineManager = scopeUser(UserRole::Manager);
    $otherManager = scopeUser(UserRole::Manager);
    $employee = scopeUser(UserRole::Employee, ['manager_id' => $lineManager->id, 'status' => 'probation']);

    expect(fn () => app(ProbationEngine::class)->managerConfirm($employee->employee, $otherManager))
        ->toThrow(ApprovalNotPermitted::class);

    app(ProbationEngine::class)->managerConfirm($employee->employee->fresh(), $lineManager);
    expect($employee->employee->fresh()->probation_confirmed_at)->not->toBeNull();
});

// ── Onboarding / offboarding ────────────────────────────────────────────────

test('the checklist cannot be repointed at another employee from the browser', function () {
    $hr = scopeUser(UserRole::HrAdmin);
    $employee = scopeUser(UserRole::Employee);

    Livewire::actingAs($hr)->test(OnboardingChecklist::class, ['employee' => $employee->employee->id])
        ->set('employeeId', 999);
})->throws(CannotUpdateLockedPropertyException::class);

test('HR cannot offboard themselves', function () {
    $hr = scopeUser(UserRole::HrAdmin);

    Livewire::actingAs($hr)->test(OffboardingManager::class)
        ->call('selectEmployee', $hr->employee->id)
        ->assertForbidden();
});

test('the final settlement amount is set by payroll staff, not by a Director', function () {
    $director = scopeUser(UserRole::Director);
    $employee = scopeUser(UserRole::Employee);

    Livewire::actingAs($director)->test(OffboardingManager::class)
        ->call('selectEmployee', $employee->employee->id)
        ->set('lastWorkingDay', now()->addDays(20)->toDateString())
        ->set('finalSettlementAmount', 999999)
        ->set('finalSettlementDone', true)
        ->call('processOffboarding');

    $exit = ExitRecord::where('employee_id', $employee->employee->id)->first();
    expect($exit)->not->toBeNull()
        ->and((float) $exit->final_settlement_amount)->toBe(0.0)
        ->and((bool) $exit->final_settlement_done)->toBeFalse();
});

// ── Employee self-service dashboard (spec §5.4) ─────────────────────────────

test('the employee dashboard lists colleagues on leave this week by name and dates only', function () {
    $dept = Department::factory()->create();
    $me = scopeUser(UserRole::Employee, ['department_id' => $dept->id]);
    $colleague = scopeUser(UserRole::Employee, ['department_id' => $dept->id]);
    $type = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true]);
    LeaveRequest::create([
        'employee_id' => $colleague->employee->id, 'leave_type_id' => $type->id,
        'start_date' => now()->startOfWeek()->toDateString(), 'end_date' => now()->startOfWeek()->toDateString(),
        'days' => 1, 'reason' => 'Private medical appointment', 'status' => 'approved',
    ]);

    Livewire::actingAs($me)->test(Dashboard::class)
        ->assertSee('On leave this week')
        ->assertSee($colleague->name)
        ->assertDontSee('Private medical appointment');
});

test('MDL shutdown days show on the leave card and are not counted as absences', function () {
    $this->travelTo(now()->setDate(2026, 12, 31)->setTime(12, 0));
    LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true]);
    foreach (range(26, 30) as $day) {
        DecemberMandatoryDay::create(['year' => 2026, 'date' => "2026-12-{$day}", 'description' => 'Company shutdown']);
    }
    $me = scopeUser(UserRole::Employee, ['joining_date' => '2024-01-08']);

    $data = app(EmployeeDashboardService::class)->build($me);
    $mdlDays = collect($data['attendance']['days'])->where('state', 'mdl');

    expect($mdlDays)->toHaveCount(5)
        ->and(collect($data['attendance']['days'])->whereIn('date', ['2026-12-28', '2026-12-29'])->pluck('state')->unique()->all())->toBe(['mdl']);
});

test('clocking in from the dashboard records the chosen work mode', function () {
    $me = scopeUser(UserRole::Employee);
    // WFH is chosen at clock-in on a day with an approved request.
    WfhRequest::create(['employee_id' => $me->employee->id, 'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
        'reason' => 'Boiler repair', 'status' => 'approved']);

    Livewire::actingAs($me)->test(Dashboard::class)->call('clockIn', null, null, 'wfh');

    expect(Attendance::where('employee_id', $me->employee->id)->value('work_mode'))->toBe('wfh');
});
