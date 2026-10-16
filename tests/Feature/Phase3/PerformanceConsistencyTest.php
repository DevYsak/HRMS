<?php

use App\Livewire\ManagerDashboard;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\AiDataTools;
use App\Services\EmployeeDashboardService;
use App\Services\People\Employee360Service;
use App\Services\Performance\PerformanceStatusService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * P3-06: one current cycle and one status vocabulary everywhere. 20 Nov
 * 2026: the active cycle "Q4 2026" (Oct–Dec, self review due 15 Nov) is
 * current. A locked cycle starting later must not be taken for it.
 *  - Ava: self review not submitted → Overdue.
 *  - Ben: self review submitted → Manager Review Pending.
 */
function pcUser(string $slug, Department $department, string $name, array $employee = []): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null, ...$employee]);

    return $user->fresh();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-20 10:00'));
    $dept = Department::factory()->create();
    $this->manager = pcUser('manager', $dept, 'Mia Manager');
    $this->hr = pcUser('hr_admin', $dept, 'Hana HR');
    $this->ava = pcUser('employee', $dept, 'Ava Overdue', ['manager_id' => $this->manager->id]);
    $this->ben = pcUser('employee', $dept, 'Ben Submitted', ['manager_id' => $this->manager->id]);

    $template = PerformanceTemplate::create(['name' => 'Quarterly', 'code' => 'QTR', 'created_by' => $this->hr->id]);
    $this->cycle = PerformanceCycle::create(['template_id' => $template->id, 'name' => 'Q4 2026', 'cycle_type' => 'quarterly', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31',
        'self_review_deadline' => '2026-11-15', 'status' => 'active', 'created_by' => $this->hr->id]);
    PerformanceCycle::create(['template_id' => $template->id, 'name' => 'Decoy locked', 'cycle_type' => 'quarterly', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'locked', 'created_by' => $this->hr->id]);

    PerformanceReview::create(['performance_cycle_id' => $this->cycle->id, 'template_id' => $template->id, 'employee_id' => $this->ava->employee->id, 'type' => 'self', 'status' => 'pending']);
    PerformanceReview::create(['performance_cycle_id' => $this->cycle->id, 'template_id' => $template->id, 'employee_id' => $this->ben->employee->id, 'type' => 'self', 'status' => 'submitted', 'self_submitted_at' => now()]);
});

test('the employee dashboard, Team View, Employee 360 and AI use the same cycle and states', function () {
    expect(app(PerformanceStatusService::class)->currentCycle()->name)->toBe('Q4 2026');

    $ava = app(EmployeeDashboardService::class)->build($this->ava)['performance'];
    $ben = app(EmployeeDashboardService::class)->build($this->ben)['performance'];
    expect($ava['cycle']['name'])->toBe('Q4 2026')
        ->and($ava['review_status'])->toBe('Overdue')
        ->and($ava['pending_self_reviews'])->toHaveCount(1)
        ->and($ben['review_status'])->toBe('Manager Review Pending')
        ->and($ben['pending_self_reviews'])->toHaveCount(0);

    Livewire::actingAs($this->manager)->test(ManagerDashboard::class)
        ->assertViewHas('reviewSummary', fn ($s) => $s['overdue'] === 1 && $s['manager_review_pending'] === 1 && $s['cycle']->name === 'Q4 2026');

    $p360 = app(Employee360Service::class)->build($this->hr, $this->ava->employee, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-20'))['performance'];
    expect(json_encode($p360))->toContain('Overdue');

    $ai = app(AiDataTools::class)->reviews($this->hr);
    expect([$ai['overdue'], $ai['manager_review_pending']])->toBe([1, 1]);
});

test('the performance CSV shows the same status as the screens, with the workflow step beside it', function () {
    $csv = $this->actingAs($this->hr)->get(route('reports.performance-summary'))->assertOk()->streamedContent();
    $lines = collect(preg_split('/\R/', trim($csv)))->map(fn ($l) => str_getcsv($l));
    $byName = $lines->slice(1)->keyBy(1)->map(fn ($row) => array_combine($lines->first(), $row));

    expect($byName['Ava Overdue']['Status'])->toBe('Overdue')
        ->and($byName['Ben Submitted']['Status'])->toBe('Manager Review Pending')
        ->and($byName['Ben Submitted']['Workflow Step'])->toBe('Submitted (Self)');
});
