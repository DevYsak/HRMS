<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\ExecutiveDashboard;
use App\Livewire\FinanceDashboard;
use App\Livewire\Performance\KpiDashboard;
use App\Models\Employee;
use App\Models\PerformanceCycle;
use App\Models\PerformanceTemplate;
use App\Models\User;
use Livewire\Livewire;

test('executive dashboard shows workforce analytics and hides payroll widgets', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
    Employee::factory()->count(2)->create(['status' => 'active']);

    Livewire::test(ExecutiveDashboard::class)
        ->assertOk()
        ->assertSee('Attendance Today')
        ->assertSee('Attrition')
        ->assertSee('Promotions')
        ->assertSee('Department Health')
        ->assertSee('Alerts')
        ->assertDontSee('Payroll Status');
});

test('hr dashboard shows pending approvals and hides payroll widgets', function () {
    $user = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Pending Approvals')
        ->assertSee('Regularisations')
        ->assertDontSee('Draft cycles open')
        ->assertDontSee('Payroll Completion');
});

test('manager dashboard shows team KPI scores', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'status' => 'active']);
    $this->actingAs($manager);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Team KPI Scores');
});

test('employee dashboard shows my KPIs and notifications and hides payslips', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('My KPI Score')
        ->assertSee('Recent Notifications')
        ->assertDontSee('Recent Payslips')
        ->assertDontSee('Net Salary');
});

test('kpi dashboard shows performer segments', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($admin);

    $template = PerformanceTemplate::create([
        'name' => 'Test Template',
        'code' => 'TPL-TEST',
        'created_by' => $admin->id,
    ]);

    PerformanceCycle::create([
        'name' => 'Q1 Test Cycle',
        'template_id' => $template->id,
        'cycle_type' => 'quarterly',
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
        'status' => 'active',
        'created_by' => $admin->id,
    ]);

    Livewire::test(KpiDashboard::class)
        ->assertOk()
        ->assertSee('Top Performers')
        ->assertSee('At Risk')
        ->assertSee('Promotion Ready');
});

test('finance dashboard hides payroll cost widgets', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Finance]));

    Livewire::test(FinanceDashboard::class)
        ->assertOk()
        ->assertSee('temporarily hidden')
        ->assertDontSee('Cycle A Payout')
        ->assertDontSee('Total Payout');
});
