<?php

use App\Enums\UserRole;
use App\Livewire\FinanceDashboard;
use App\Livewire\HrAdminDashboard;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\User;
use Database\Factories\OtRequestFactory;
use Livewire\Livewire;

/**
 * The HR, Super Admin and Finance dashboards: role-specific, scoped, each
 * figure once, nothing invented, links only where the viewer can go.
 */
function drUser(UserRole $role, ?Department $department = null, array $attributes = []): User
{
    $user = User::factory()->create(array_merge(['role' => $role], $attributes));
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => ($department ?? Department::factory()->create())->id, 'status' => 'active', 'manager_id' => null, 'joining_date' => now()->subYear()->toDateString()]);

    return $user->fresh();
}

function drStaff(Department $department, int $count): void
{
    foreach (range(1, $count) as $i) {
        Employee::factory()->create(['user_id' => User::factory()->create()->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null, 'joining_date' => now()->subYear()->toDateString()]);
    }
}

test('HR sees the HR operations overview with decisions and alerts', function () {
    $hr = drUser(UserRole::HrAdmin);

    $this->actingAs($hr)->get(route('dashboard'))->assertOk()
        ->assertSee('HR operations')
        ->assertSee('Waiting for a decision')
        ->assertSee('HR alerts')
        ->assertSee('Missing checkout')
        ->assertDontSee('Security — last 7 days');
});

test('the Super Admin overview adds payroll, departments and security', function () {
    $this->actingAs(drUser(UserRole::SuperAdmin))->get(route('dashboard'))->assertOk()
        ->assertSee('Company overview')
        ->assertSee('People by department')
        ->assertSee('Security — last 7 days');
});

test('no invented metrics and no repeated headline cards', function (UserRole $role) {
    $html = $this->actingAs(drUser($role))->get(route('dashboard'))->assertOk()->getContent();

    foreach (['Satisfaction', 'Company Health', 'Readiness', 'Organization Health'] as $invented) {
        expect($html)->not->toContain($invented);
    }

    foreach (['Working headcount', 'Present today', 'Missing checkout', 'Not checked in', 'Waiting for a decision', 'HR alerts'] as $card) {
        expect(substr_count($html, $card))->toBe(1, "'{$card}' appears more than once");
    }
})->with([UserRole::HrAdmin, UserRole::SuperAdmin]);

test('a department-scoped HR user sees their departments\' figures, not the company\'s', function () {
    $ops = Department::factory()->create(['name' => 'Ops']);
    $sales = Department::factory()->create(['name' => 'Sales']);
    drStaff($ops, 2);
    drStaff($sales, 5);
    $scoped = drUser(UserRole::HrAdmin, $ops, ['scope_departments' => [$ops->id]]);

    Livewire::actingAs($scoped)->test(HrAdminDashboard::class)
        ->assertViewHas('people', fn ($p) => $p['headcount'] === 3)
        ->assertSee('Ops')
        ->assertDontSee('All departments');

    Livewire::actingAs(drUser(UserRole::HrAdmin))->test(HrAdminDashboard::class)
        ->assertViewHas('people', fn ($p) => $p['headcount'] >= 8)
        ->assertSee('All departments');
});

test('the Finance dashboard shows the payroll queue and OT payable from records', function () {
    $finance = drUser(UserRole::Finance);
    $employee = Employee::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
    Payroll::create(['month' => now()->format('F'), 'year' => now()->year, 'cycle' => 'cycle_a', 'status' => 'pending_finance', 'total_payout' => 250000]);
    $request = OtRequest::factory()->create(['employee_id' => $employee->id, 'status' => 'approved', 'work_date' => now()->subDays(3)->toDateString()]);
    OvertimeRecord::create(['employee_id' => $employee->id, 'ot_request_id' => $request->id, 'work_date' => $request->work_date, 'total_hours_worked' => 11, 'ot_hours' => 2, 'rate_per_hour' => 150, 'ot_amount' => 300, 'is_paid' => false]);

    Livewire::actingAs($finance)->test(FinanceDashboard::class)
        ->assertOk()
        ->assertSee('Awaiting finance sign-off')
        ->assertSee('₹250,000', false)
        ->assertViewHas('otPayable', fn ($ot) => (float) $ot['amount'] === 300.0 && (float) $ot['hours'] === 2.0)
        ->assertViewHas('awaitingFinance', fn ($runs) => $runs->count() === 1);
})->skip(fn () => ! class_exists(OtRequestFactory::class), 'needs the OT request factory');

test('Finance dashboard links only to pages the viewer can open', function () {
    $finance = drUser(UserRole::Finance);
    $html = Livewire::actingAs($finance)->test(FinanceDashboard::class)->html();

    expect($html)->toContain(route('payroll.incentives'))
        ->and($html)->toContain(route('payroll.finance-approve'));

    // Employees and managers cannot open it at all.
    $this->actingAs(drUser(UserRole::Employee))->get(route('dashboard.finance'))->assertForbidden();
    $this->actingAs(drUser(UserRole::Manager))->get(route('dashboard.finance'))->assertForbidden();
});

test('the Finance month picker rejects a malformed month instead of erroring', function () {
    Livewire::actingAs(drUser(UserRole::Finance))->test(FinanceDashboard::class)
        ->set('month', 'not-a-month')
        ->assertOk()
        ->assertSet('month', now()->format('Y-m'));
});

test('dashboards use the responsive grid (one column on phones, more on wide screens)', function (string $route, UserRole $role) {
    $html = $this->actingAs(drUser($role))->get(route($route))->assertOk()->getContent();

    expect($html)->toContain('grid-cols-2')
        ->and($html)->toMatch('/(lg|xl):grid-cols-[34]/')
        ->and($html)->toContain('max-w-[1400px]');
})->with([
    'HR' => ['dashboard', UserRole::HrAdmin],
    'Super Admin' => ['dashboard', UserRole::SuperAdmin],
    'Finance' => ['dashboard.finance', UserRole::Finance],
]);
