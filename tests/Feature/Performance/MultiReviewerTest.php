<?php

use App\Livewire\Performance\ReviewTasks;
use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\Employee;
use App\Models\PerformanceCategory;
use App\Models\PerformanceComponent;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewScore;
use App\Models\PerformanceTemplate;
use App\Models\ReviewWeightage;
use App\Models\User;
use App\Notifications\ReviewParticipantAssignedNotification;
use App\Services\Performance\ParticipantService;
use App\Services\Performance\ReviewWorkflowService;
use App\Services\Teams\TeamService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * v4 Phase D — multi-reviewer performance reviews: participant creation,
 * weight resolution + re-normalisation, composite scoring, and the
 * Review Tasks queue.
 */
function perfFixture(): array
{
    $actor = User::factory()->create();

    $template = PerformanceTemplate::create([
        'name' => 'Global KPI', 'code' => 'GK'.rand(100, 999), 'applies_to_type' => 'global',
        'cycle_type' => 'quarterly', 'is_active' => true, 'created_by' => $actor->id,
    ]);

    $category = PerformanceCategory::create([
        'template_id' => $template->id, 'name' => 'Core', 'code' => 'CORE', 'color' => '#333', 'sort_order' => 1,
    ]);

    $component = PerformanceComponent::create([
        'template_id' => $template->id, 'category_id' => $category->id,
        'name' => 'Delivery', 'scoring_type' => 'manual', 'max_score' => 100, 'weight_percent' => 100, 'sort_order' => 1,
    ]);

    $cycle = PerformanceCycle::create([
        'name' => 'Test Cycle', 'template_id' => $template->id, 'cycle_type' => 'quarterly',
        'start_date' => '2026-07-01', 'end_date' => '2026-09-30', 'status' => 'draft', 'created_by' => $actor->id,
    ]);

    return [$actor, $template, $component, $cycle];
}

/** Employee with a team lead and a department head. */
function teamedEmployee(): array
{
    $head = User::factory()->create(['name' => 'HEAD']);
    $dept = Department::factory()->create(['head_id' => $head->id]);

    $leadUser = User::factory()->create(['name' => 'LEAD']);
    $leadEmp = Employee::factory()->create(['department_id' => $dept->id, 'user_id' => $leadUser->id]);

    $team = DepartmentTeam::create([
        'department_id' => $dept->id, 'name' => 'Web', 'team_lead_id' => $leadEmp->id, 'status' => 'active',
    ]);

    $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
    app(TeamService::class)->assign($employee, $team);

    return [$employee->fresh(), $leadUser, $head, $dept];
}

test('activating a cycle creates self, team lead and department head participants with 20/50/30 weights', function () {
    Notification::fake();

    [$actor, , , $cycle] = perfFixture();
    [$employee, $leadUser, $head] = teamedEmployee();

    app(ReviewWorkflowService::class)->activateCycle($cycle->fresh(), $actor);

    $review = PerformanceReview::where('employee_id', $employee->id)->firstOrFail();
    $participants = $review->participants->keyBy('reviewer_role');

    expect($participants)->toHaveCount(3)
        ->and($participants['self']->reviewer_id)->toBe($employee->user_id)
        ->and($participants['self']->weight_percent)->toBe(20.0)
        ->and($participants['team_lead']->reviewer_id)->toBe($leadUser->id)
        ->and($participants['team_lead']->weight_percent)->toBe(50.0)
        ->and($participants['department_head']->reviewer_id)->toBe($head->id)
        ->and($participants['department_head']->weight_percent)->toBe(30.0);

    Notification::assertSentTo($leadUser, ReviewParticipantAssignedNotification::class);
    Notification::assertSentTo($head, ReviewParticipantAssignedNotification::class);
});

test('weights renormalise when a rung is missing and honour department overrides', function () {
    Notification::fake();

    // Employee with no team and no manager — only self + dept head resolve.
    $head = User::factory()->create();
    $dept = Department::factory()->create(['head_id' => $head->id]);
    ReviewWeightage::create(['department_id' => $dept->id, 'reviewer_role' => 'self', 'weight_percent' => 40]);
    ReviewWeightage::create(['department_id' => $dept->id, 'reviewer_role' => 'department_head', 'weight_percent' => 60]);

    $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'manager_id' => null]);

    [$actor, $template, , $cycle] = perfFixture();
    $review = PerformanceReview::create([
        'employee_id' => $employee->id, 'performance_cycle_id' => $cycle->id,
        'template_id' => $template->id, 'type' => 'self', 'status' => 'draft',
    ]);

    $participants = app(ParticipantService::class)->createParticipantsFor($review)->keyBy('reviewer_role');

    expect($participants)->toHaveCount(2)
        ->and($participants['self']->weight_percent)->toBe(40.0)
        ->and($participants['department_head']->weight_percent)->toBe(60.0);
});

test('the composite score weights every submitted participant', function () {
    Notification::fake();

    [$actor, $template, $component, $cycle] = perfFixture();
    [$employee, $leadUser, $head] = teamedEmployee();

    $workflow = app(ReviewWorkflowService::class);
    $workflow->activateCycle($cycle->fresh(), $actor);

    $review = PerformanceReview::where('employee_id', $employee->id)->firstOrFail();
    $service = app(ParticipantService::class);
    $byRole = $review->participants->keyBy('reviewer_role');

    $service->submit($byRole['self'], [['component_id' => $component->id, 'score' => 80.0, 'comment' => null]]);
    $service->submit($byRole['team_lead'], [['component_id' => $component->id, 'score' => 60.0, 'comment' => 'solid']]);
    $service->submit($byRole['department_head'], [['component_id' => $component->id, 'score' => 90.0, 'comment' => null]]);

    // 0.2×80 + 0.5×60 + 0.3×90 = 73
    $workflow->lockReview($review->fresh(), $actor);

    expect($review->fresh()->final_score)->toBe(73.0)
        ->and($review->fresh()->status)->toBe('locked');
});

test('an HR score overrides the participant composite', function () {
    Notification::fake();

    [$actor, $template, $component, $cycle] = perfFixture();
    [$employee] = teamedEmployee();

    $workflow = app(ReviewWorkflowService::class);
    $workflow->activateCycle($cycle->fresh(), $actor);

    $review = PerformanceReview::where('employee_id', $employee->id)->firstOrFail();
    $service = app(ParticipantService::class);
    foreach ($review->participants as $participant) {
        $service->submit($participant, [['component_id' => $component->id, 'score' => 50.0, 'comment' => null]]);
    }

    PerformanceReviewScore::where('review_id', $review->id)->update(['hr_score' => 95.0]);
    $review->fresh()->update(['status' => 'hr_reviewed']);

    $workflow->lockReview($review->fresh(), $actor);

    expect($review->fresh()->final_score)->toBe(95.0);
});

test('locking is blocked while participants are pending, unless HR reviewed', function () {
    Notification::fake();

    [$actor, , $component, $cycle] = perfFixture();
    [$employee] = teamedEmployee();

    $workflow = app(ReviewWorkflowService::class);
    $workflow->activateCycle($cycle->fresh(), $actor);

    $review = PerformanceReview::where('employee_id', $employee->id)->firstOrFail();
    $service = app(ParticipantService::class);
    $byRole = $review->participants->keyBy('reviewer_role');

    // Only the lead submits — head still pending.
    $service->submit($byRole['team_lead'], [['component_id' => $component->id, 'score' => 70.0, 'comment' => null]]);

    expect(fn () => $workflow->lockReview($review->fresh(), $actor))
        ->toThrow(DomainException::class, 'Waiting on reviewer submissions');
});

test('adding an additional reviewer renormalises existing weights to keep 100 total', function () {
    Notification::fake();

    [$actor, $template, , $cycle] = perfFixture();
    [$employee] = teamedEmployee();

    app(ReviewWorkflowService::class)->activateCycle($cycle->fresh(), $actor);
    $review = PerformanceReview::where('employee_id', $employee->id)->firstOrFail();

    $extra = User::factory()->create(['name' => 'PM']);
    app(ParticipantService::class)->addAdditionalReviewer($review, $extra, 20.0);

    $weights = $review->fresh()->participants->pluck('weight_percent', 'reviewer_role');

    expect($weights['additional'])->toBe(20.0)
        ->and($weights['self'])->toBe(16.0)      // 20 × 0.8
        ->and($weights['team_lead'])->toBe(40.0) // 50 × 0.8
        ->and($weights['department_head'])->toBe(24.0) // 30 × 0.8
        ->and((float) $review->fresh()->participants->sum('weight_percent'))->toBe(100.0);
});

test('legacy wide-column scores migrate into participant rows', function () {
    [$actor, $template, $component, $cycle] = perfFixture();

    $managerUser = User::factory()->create();
    $managerEmp = Employee::factory()->create(['user_id' => $managerUser->id]);
    $employee = Employee::factory()->create(['status' => 'active']);

    $review = PerformanceReview::create([
        'employee_id' => $employee->id, 'performance_cycle_id' => $cycle->id, 'template_id' => $template->id,
        'reviewer_id' => $managerEmp->id, 'type' => 'self', 'status' => 'manager_reviewed',
        'self_submitted_at' => now()->subDays(2), 'manager_submitted_at' => now()->subDay(),
    ]);

    PerformanceReviewScore::create([
        'review_id' => $review->id, 'component_id' => $component->id,
        'self_score' => 75, 'manager_score' => 65, 'self_comment' => 'did well',
    ]);

    $stats = app(ParticipantService::class)->migrateWideScores();

    $participants = $review->fresh()->participants->keyBy('reviewer_role');

    expect($stats['participants_created'])->toBe(2)
        ->and($stats['scores_copied'])->toBe(2)
        ->and($participants['self']->status)->toBe('submitted')
        ->and($participants['self']->scores->first()->score)->toBe(75.0)
        ->and($participants['team_lead']->reviewer_id)->toBe($managerUser->id)
        ->and($participants['team_lead']->scores->first()->score)->toBe(65.0);

    // Idempotent
    $again = app(ParticipantService::class)->migrateWideScores();
    expect($again['participants_created'])->toBe(0);
});

test('a team lead can submit scores from the Review Tasks page', function () {
    Notification::fake();

    [$actor, , $component, $cycle] = perfFixture();
    [$employee, $leadUser] = teamedEmployee();

    app(ReviewWorkflowService::class)->activateCycle($cycle->fresh(), $actor);
    $review = PerformanceReview::where('employee_id', $employee->id)->firstOrFail();
    $participant = $review->participants()->where('reviewer_id', $leadUser->id)->firstOrFail();

    Livewire::actingAs($leadUser)->test(ReviewTasks::class)
        ->call('openTask', $participant->id)
        ->assertSet('showForm', true)
        ->set("entries.{$component->id}.score", 85)
        ->set("entries.{$component->id}.comment", 'great quarter')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $participant = $participant->fresh();
    expect($participant->status)->toBe('submitted')
        ->and($participant->scores->first()->score)->toBe(85.0)
        ->and($review->fresh()->status)->toBe('manager_reviewed')
        // Wide-column mirror keeps legacy dashboards working
        ->and((float) PerformanceReviewScore::where('review_id', $review->id)->value('manager_score'))->toBe(85.0);
});

test('a participant cannot open someone else\'s task', function () {
    Notification::fake();

    [$actor, , , $cycle] = perfFixture();
    [$employee, $leadUser] = teamedEmployee();

    app(ReviewWorkflowService::class)->activateCycle($cycle->fresh(), $actor);
    $review = PerformanceReview::where('employee_id', $employee->id)->firstOrFail();
    $participant = $review->participants()->where('reviewer_id', $leadUser->id)->firstOrFail();

    $outsider = User::factory()->create();

    expect(fn () => Livewire::actingAs($outsider)->test(ReviewTasks::class)
        ->call('openTask', $participant->id))
        ->toThrow(ModelNotFoundException::class);
});
