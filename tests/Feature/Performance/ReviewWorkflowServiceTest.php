<?php

use App\Models\Employee;
use App\Models\PerformanceCategory;
use App\Models\PerformanceComponent;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewScore;
use App\Models\PerformanceTemplate;
use App\Models\User;
use App\Notifications\KpiAssignedNotification;
use App\Notifications\ReviewCycleStartedNotification;
use App\Services\Performance\ReviewWorkflowService;
use Illuminate\Support\Facades\Notification;

test('activating a performance cycle creates reviews without a legacy review cycle id', function () {
    Notification::fake();

    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['status' => 'active']);

    $template = PerformanceTemplate::create([
        'name' => 'Quarterly KPI Template',
        'code' => 'QKPI001',
        'applies_to_type' => 'global',
        'cycle_type' => 'quarterly',
        'is_active' => true,
        'created_by' => $actor->id,
    ]);

    $category = PerformanceCategory::create([
        'template_id' => $template->id,
        'name' => 'Technical',
        'code' => 'TECH',
        'color' => '#6366F1',
        'sort_order' => 1,
    ]);

    $component = PerformanceComponent::create([
        'template_id' => $template->id,
        'category_id' => $category->id,
        'name' => 'Delivery Quality',
        'description' => 'Quality of task delivery',
        'scoring_type' => 'manual',
        'max_score' => 100,
        'weight_percent' => 100,
        'sort_order' => 1,
    ]);

    $cycle = PerformanceCycle::create([
        'name' => 'Q2 June - Nov',
        'template_id' => $template->id,
        'cycle_type' => 'quarterly',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'self_review_deadline' => '2026-06-10',
        'manager_review_deadline' => '2026-06-20',
        'hr_review_deadline' => '2026-06-25',
        'status' => 'draft',
        'created_by' => $actor->id,
    ]);

    app(ReviewWorkflowService::class)->activateCycle($cycle->fresh(), $actor);

    $review = PerformanceReview::query()
        ->where('employee_id', $employee->id)
        ->where('performance_cycle_id', $cycle->id)
        ->where('type', 'self')
        ->first();

    expect($review)->not->toBeNull()
        ->and($review->review_cycle_id)->toBeNull()
        ->and($review->template_id)->toBe($template->id)
        ->and($review->status)->toBe('draft')
        ->and($cycle->fresh()->status)->toBe('active')
        ->and(PerformanceReviewScore::query()
            ->where('review_id', $review->id)
            ->where('component_id', $component->id)
            ->exists())->toBeTrue();

    Notification::assertSentTo($employee->user, KpiAssignedNotification::class);
    Notification::assertSentTo($employee->user, ReviewCycleStartedNotification::class);
});
