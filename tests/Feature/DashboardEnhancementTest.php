<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\ExecutiveDashboard;
use App\Livewire\FinanceDashboard;
use App\Livewire\ManagerDashboard;
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
        ->assertSee('Executive Summary')
        ->assertSee('Attrition')
        ->assertSee('Department Ranking')
        ->assertDontSee('Organization Health')
        ->assertDontSee('Satisfaction')
        ->assertDontSee('Company Health')
        ->assertSee('Risk Indicators')
        ->assertDontSee('Payroll Status');
});

test('hr dashboard shows pending approvals and hides payroll widgets', function () {
    $user = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $this->actingAs($user);

    // Redesigned HR overview (8 Oct 2026): approvals live in "Waiting for a
    // decision"; payroll figures are not on the HR view.
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Waiting for a decision')
        ->assertSee('Attendance regularisations')
        ->assertDontSee('Draft cycles open')
        ->assertDontSee('Payroll Completion');
});

test('manager dashboard shows team KPI scores', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'status' => 'active']);
    $this->actingAs($manager);

    // "/" forwards a Manager to their own dashboard page.
    Livewire::test(Dashboard::class)->assertRedirect(route('dashboard.manager'));

    Livewire::test(ManagerDashboard::class)
        ->assertOk()
        ->assertSee('Team KPI Scores');
});

test('employee dashboard renders the redesigned self-service widgets', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Attendance Overview')
        ->assertSee('Leave Summary')
        ->assertSee("Today's Timeline")
        ->assertSee('Quick Actions')
        ->assertSee('My Team');
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
        ->assertSee('Promotion Ready')
        // Phase 5 Performance Hub analytics
        ->assertSee('Goal Progress')
        ->assertSee('Department Ranking')
        ->assertSee('PIP Risk')
        ->assertSee('Heatmap');
});

test('finance dashboard shows the payroll queue (no longer hidden)', function () {
    // The widgets were hidden until the payroll module switch existed; the
    // Finance dashboard now shows them, behind that switch (8 Oct 2026).
    $this->actingAs(User::factory()->create(['role' => UserRole::Finance]));

    Livewire::test(FinanceDashboard::class)
        ->assertOk()
        ->assertSee('Awaiting finance sign-off')
        ->assertSee('OT payable')
        ->assertDontSee('temporarily hidden');
});
