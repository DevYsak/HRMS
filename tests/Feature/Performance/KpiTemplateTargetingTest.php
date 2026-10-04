<?php

use App\Enums\UserRole;
use App\Livewire\Performance\KpiTemplates;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\PerformanceCategory;
use App\Models\PerformanceComponent;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceTemplate;
use App\Models\User;
use App\Services\Performance\ReviewWorkflowService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Spec v3.1 §3.7 — KPIs are defined per department (or designation) and
 * reach exactly the people they are meant for. A designation template used
 * to query a column that does not exist (activation crashed); a template
 * with no target picked used to fall through to EVERY employee.
 */
function targetedCycle(string $type, ?int $targetId): PerformanceCycle
{
    $actor = User::factory()->create();
    $template = PerformanceTemplate::create([
        'name' => 'Targeted KPIs', 'code' => 'TK'.random_int(100, 999), 'applies_to_type' => $type, 'applies_to_id' => $targetId,
        'cycle_type' => 'quarterly', 'is_active' => true, 'created_by' => $actor->id,
    ]);
    $category = PerformanceCategory::create(['template_id' => $template->id, 'name' => 'Delivery', 'code' => 'DEL', 'color' => '#6366F1', 'sort_order' => 1]);
    PerformanceComponent::create([
        'template_id' => $template->id, 'category_id' => $category->id, 'name' => 'On-time delivery',
        'scoring_type' => 'manual', 'max_score' => 100, 'weight_percent' => 100, 'sort_order' => 1,
    ]);

    return PerformanceCycle::create([
        'name' => 'Q2 Oct–Dec', 'template_id' => $template->id, 'cycle_type' => 'quarterly',
        'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'self_review_deadline' => '2026-12-10',
        'manager_review_deadline' => '2026-12-20', 'hr_review_deadline' => '2026-12-27',
        'status' => 'draft', 'created_by' => $actor->id,
    ]);
}

beforeEach(fn () => Notification::fake());

test('a designation template reaches exactly that designation', function () {
    $engineer = JobTitle::factory()->create();
    $analyst = JobTitle::factory()->create();
    $mine = Employee::factory()->create(['status' => 'active', 'job_title_id' => $engineer->id]);
    $other = Employee::factory()->create(['status' => 'active', 'job_title_id' => $analyst->id]);
    $cycle = targetedCycle('designation', $engineer->id);

    app(ReviewWorkflowService::class)->activateCycle($cycle->fresh(), User::factory()->create());

    $reviewed = PerformanceReview::where('performance_cycle_id', $cycle->id)->pluck('employee_id');
    expect($reviewed->all())->toContain($mine->id)->not->toContain($other->id);
});

test('a targeted template with no target chosen is refused instead of going to everyone', function () {
    Employee::factory()->count(2)->create(['status' => 'active']);
    $cycle = targetedCycle('employment_type', null);

    expect(fn () => app(ReviewWorkflowService::class)->activateCycle($cycle->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(PerformanceReview::where('performance_cycle_id', $cycle->id)->count())->toBe(0);
});

test('a Role template reaches every active employee, ignoring a leftover target id', function () {
    $first = Employee::factory()->create(['status' => 'active']);
    $second = Employee::factory()->create(['status' => 'active']);
    $cycle = targetedCycle('role', 3);

    app(ReviewWorkflowService::class)->activateCycle($cycle->fresh(), User::factory()->create());

    expect(PerformanceReview::where('performance_cycle_id', $cycle->id)->pluck('employee_id')->all())
        ->toContain($first->id, $second->id);
});

test('switching Applies To clears the old target, and a targeted template names one from its own table', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $department = Department::factory()->create();
    $title = JobTitle::factory()->create();

    Livewire::actingAs($admin)->test(KpiTemplates::class)
        ->call('newTemplate')
        ->set('name', 'Sales KPIs')->set('code', 'SALES_KPI')
        ->set('applies_to_type', 'department')
        ->set('applies_to_id', $department->id)
        ->set('applies_to_type', 'designation')
        ->assertSet('applies_to_id', null)
        ->call('save')
        ->assertHasErrors(['applies_to_id' => 'required'])
        ->set('applies_to_id', $title->id)
        ->call('save')
        ->assertHasNoErrors();

    $template = PerformanceTemplate::where('code', 'SALES_KPI')->first();
    expect($template->applies_to_type)->toBe('designation')
        ->and($template->applies_to_id)->toBe($title->id);
});

test('a Role or Global template is saved without a target', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $department = Department::factory()->create();

    Livewire::actingAs($admin)->test(KpiTemplates::class)
        ->call('newTemplate')
        ->set('name', 'Everyone KPIs')->set('code', 'ALL_KPI')
        ->set('applies_to_type', 'role')
        // A stale id sent from the old picker is not kept.
        ->set('applies_to_id', $department->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(PerformanceTemplate::where('code', 'ALL_KPI')->value('applies_to_id'))->toBeNull();
});
