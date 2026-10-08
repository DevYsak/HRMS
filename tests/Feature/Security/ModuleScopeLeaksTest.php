<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\Employees\FinanceEmployeeProfile;
use App\Livewire\NotificationsPage;
use App\Livewire\Performance\KpiDashboard;
use App\Livewire\Performance\WarningLetters;
use App\Livewire\TimeOff\EmployeeLeaveDetail;
use App\Livewire\TimeOff\LeaveManagement;
use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeScorecard;
use App\Models\LeaveYear;
use App\Models\PerformanceCycle;
use App\Models\PerformanceTemplate;
use App\Models\User;
use App\Models\WarningLetter;
use App\Services\Ai\AiContextBuilder;
use App\Services\Navigation\Sidebar;
use Livewire\Livewire;

/**
 * Phase 4: every list, record and action respects the data scope — a
 * department-scoped HR user, a manager or a department head reaches only their
 * people, on screen and on the server.
 */
function mslWorld(): array
{
    $ops = Department::factory()->create(['name' => 'Ops']);
    $sales = Department::factory()->create(['name' => 'Sales']);

    $make = fn (Department $d, array $user = [], array $employee = []) => Employee::factory()->create(array_merge([
        'user_id' => User::factory()->create(array_merge(['role' => UserRole::Employee], $user))->id,
        'department_id' => $d->id,
        'status' => 'active',
        'manager_id' => null,
    ], $employee));

    $scopedHr = $make($ops, ['role' => UserRole::HrAdmin, 'scope_departments' => [$ops->id]])->user->fresh();
    $hr = $make($ops, ['role' => UserRole::HrAdmin])->user->fresh();
    $manager = $make($ops, ['role' => UserRole::Manager])->user->fresh();
    $opsPeer = $make($ops, [], ['manager_id' => $manager->id]);
    $salesPeer = $make($sales);

    return compact('ops', 'sales', 'scopedHr', 'hr', 'manager', 'opsPeer', 'salesPeer');
}

test('the Employees list shows only the viewer\'s reach', function () {
    $w = mslWorld();

    Livewire::actingAs($w['scopedHr'])->test(EmployeeIndex::class)
        ->assertSee($w['opsPeer']->user->name)
        ->assertDontSee($w['salesPeer']->user->name);

    Livewire::actingAs($w['hr'])->test(EmployeeIndex::class)
        ->assertSee($w['salesPeer']->user->name);

    Livewire::actingAs($w['manager'])->test(EmployeeIndex::class)
        ->assertSee($w['opsPeer']->user->name)
        ->assertDontSee($w['salesPeer']->user->name);
});

test('Leave Management rows and the employee page stay inside the scope', function () {
    $w = mslWorld();
    LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    Livewire::actingAs($w['scopedHr'])->test(LeaveManagement::class)
        ->assertSee($w['opsPeer']->user->name)
        ->assertDontSee($w['salesPeer']->user->name);

    Livewire::actingAs($w['scopedHr'])
        ->test(EmployeeLeaveDetail::class, ['employee' => $w['salesPeer']])
        ->assertForbidden();

    Livewire::actingAs($w['hr'])
        ->test(EmployeeLeaveDetail::class, ['employee' => $w['salesPeer']])
        ->assertOk();
});

test('a finance profile opens only inside the view_finance_profile scope', function () {
    $w = mslWorld();

    Livewire::actingAs($w['scopedHr'])
        ->test(FinanceEmployeeProfile::class, ['employee' => $w['salesPeer']])
        ->assertForbidden();

    Livewire::actingAs($w['scopedHr'])
        ->test(FinanceEmployeeProfile::class, ['employee' => $w['opsPeer']])
        ->assertOk();
});

test('warning letters: list, view and issue follow the scope', function () {
    $w = mslWorld();
    $inScope = WarningLetter::create(['employee_id' => $w['opsPeer']->id, 'issued_by' => $w['hr']->id, 'warning_type' => 'verbal', 'reason' => 'Late', 'issue_date' => now()->toDateString(), 'status' => 'issued']);
    $outOfScope = WarningLetter::create(['employee_id' => $w['salesPeer']->id, 'issued_by' => $w['hr']->id, 'warning_type' => 'verbal', 'reason' => 'Absent', 'issue_date' => now()->toDateString(), 'status' => 'issued']);

    $page = Livewire::actingAs($w['scopedHr'])->test(WarningLetters::class)
        ->assertSee($w['opsPeer']->user->name)
        ->assertDontSee($w['salesPeer']->user->name);

    $page->call('viewWarning', $outOfScope->id)->assertForbidden();

    Livewire::actingAs($w['scopedHr'])->test(WarningLetters::class)
        ->set('employee_id', (string) $w['salesPeer']->id)
        ->set('warning_type', 'verbal')
        ->set('reason', 'Out of scope attempt')
        ->set('issue_date', now()->toDateString())
        ->call('issueWarning')
        ->assertForbidden();

    expect(WarningLetter::where('employee_id', $w['salesPeer']->id)->count())->toBe(1)
        ->and($inScope->exists)->toBeTrue();
});

test('the reminder centre shows only reachable employees, decided by scope not role', function () {
    $w = mslWorld();
    foreach ([$w['opsPeer'], $w['salesPeer']] as $employee) {
        Document::create([
            'title' => 'Visa of '.$employee->user->name, 'category' => 'personal', 'visibility' => 'restricted',
            'employee_id' => $employee->id, 'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 10, 'uploaded_by' => $w['hr']->id, 'expires_at' => now()->addDays(10)->toDateString(),
        ]);
    }

    Livewire::actingAs($w['scopedHr'])->test(NotificationsPage::class)
        ->set('view', 'reminders')
        ->assertSee('Visa of '.$w['opsPeer']->user->name)
        ->assertDontSee('Visa of '.$w['salesPeer']->user->name);

    // An employee with no reports has no reminder centre at all.
    Livewire::actingAs($w['salesPeer']->user)->test(NotificationsPage::class)
        ->assertViewHas('canViewReminders', false);
});

test('the AI context never counts beyond the viewer\'s reach', function () {
    $w = mslWorld();
    $builder = app(AiContextBuilder::class);

    expect($builder->organisation($w['salesPeer']->user))->toBe([])
        ->and($builder->organisation($w['hr'])['organisation']['scope'])->toBe('company')
        ->and($builder->organisation($w['scopedHr'])['organisation']['scope'])->not->toBe('company');

    $scopedHeadcount = $builder->organisation($w['scopedHr'])['organisation']['headcount'] ?? null;
    $companyHeadcount = $builder->organisation($w['hr'])['organisation']['headcount'];

    expect($scopedHeadcount)->toBeLessThan($companyHeadcount);
});

// ── Direct URLs: the server refuses what the screen would not show ─────────

test('direct URLs to out-of-scope records are refused', function () {
    $w = mslWorld();
    LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    $this->actingAs($w['scopedHr'])->get(route('employees.finance-profile', $w['salesPeer']))->assertForbidden();
    $this->actingAs($w['scopedHr'])->get(route('time-off.leave-management.employee', $w['salesPeer']))->assertForbidden();

    $this->actingAs($w['scopedHr'])->get(route('employees.finance-profile', $w['opsPeer']))->assertOk();
    $this->actingAs($w['scopedHr'])->get(route('time-off.leave-management.employee', $w['opsPeer']))->assertOk();

    // A manager has neither permission at all: refused by the route.
    $this->actingAs($w['manager'])->get(route('employees.finance-profile', $w['opsPeer']))->assertForbidden();
    $this->actingAs($w['manager'])->get(route('time-off.leave-management.employee', $w['opsPeer']))->assertForbidden();
});

test('the KPI dashboard lists only scorecards inside the viewer\'s scope', function () {
    $w = mslWorld();
    $template = PerformanceTemplate::firstOrCreate(['code' => 'MSL-T'], ['name' => 'MSL Template', 'applies_to_type' => 'global', 'cycle_type' => 'quarterly', 'is_active' => true, 'created_by' => $w['hr']->id]);
    $cycle = PerformanceCycle::create(['name' => 'Q MSL', 'template_id' => $template->id, 'cycle_type' => 'quarterly',
        'start_date' => now()->subMonths(3)->toDateString(), 'end_date' => now()->toDateString(), 'status' => 'completed', 'created_by' => $w['hr']->id]);
    foreach ([$w['opsPeer'], $w['salesPeer']] as $employee) {
        EmployeeScorecard::create(['employee_id' => $employee->id, 'performance_cycle_id' => $cycle->id, 'template_id' => $template->id,
            'total_weighted_score' => 80, 'final_score' => 80, 'grade' => 'a', 'generated_at' => now()]);
    }

    Livewire::actingAs($w['scopedHr'])->test(KpiDashboard::class)
        ->set('selectedCycleId', $cycle->id)
        ->assertSee($w['opsPeer']->user->name)
        ->assertDontSee($w['salesPeer']->user->name);

    Livewire::actingAs($w['hr'])->test(KpiDashboard::class)
        ->set('selectedCycleId', $cycle->id)
        ->assertSee($w['salesPeer']->user->name);
});

test('a manager reaches only their own team on the Employees list and warning letters', function () {
    $w = mslWorld();
    $otherManager = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Manager])->id,
        'department_id' => $w['ops']->id, 'status' => 'active', 'manager_id' => null,
    ])->user;
    $otherTeam = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee, 'name' => 'OTHER TEAM PERSON'])->id,
        'department_id' => $w['ops']->id, 'status' => 'active', 'manager_id' => $otherManager->id,
    ]);

    // Same department, different team: still not reached.
    Livewire::actingAs($w['manager'])->test(EmployeeIndex::class)
        ->assertSee($w['opsPeer']->user->name)
        ->assertDontSee('OTHER TEAM PERSON');

    // Warning letters need employee management + the warning permission.
    $this->actingAs($w['manager'])->get(route('performance.warnings.manage'))->assertForbidden();
    expect($otherTeam->exists)->toBeTrue();
});

test('navigation matches route authorisation for the Phase 4 screens', function () {
    $w = mslWorld();
    $labels = fn (User $u) => collect(app(Sidebar::class)->groups($u))->flatMap(fn ($g) => collect($g['items'])->pluck('route'))->all();

    foreach ([$w['scopedHr'], $w['hr'], $w['manager'], $w['salesPeer']->user] as $user) {
        foreach (['employees.index', 'time-off.leave-management', 'performance.warnings.manage', 'performance.kpi-dashboard'] as $route) {
            $shown = in_array($route, $labels($user), true);
            $status = $this->actingAs($user)->get(route($route))->getStatusCode();

            if ($shown) {
                expect($status)->toBe(200, "{$user->role->value}: {$route} shown but returns {$status}");
            }
        }
    }
});
