<?php

namespace App\Services\Performance;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewScore;
use App\Models\PerformanceTemplate;
use App\Models\ReviewWeightage;
use App\Models\User;
use App\Notifications\KpiAssignedNotification;
use App\Notifications\ReviewCycleStartedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ReviewWorkflowService
{
    public function __construct(
        private readonly KpiScoringEngine $scoringEngine,
        private readonly TimelineService $timeline,
        private readonly ParticipantService $participants,
    ) {}

    /**
     * Activate a cycle — assigns KPIs to all applicable employees.
     */
    public function activateCycle(PerformanceCycle $cycle, User $actor): void
    {
        $this->assertCycleStatus($cycle, 'draft');

        $template = $cycle->template;
        $employees = $this->resolveEmployeesForTemplate($template);

        DB::transaction(function () use ($cycle, $template, $employees, $actor) {
            foreach ($employees as $employee) {
                $review = PerformanceReview::firstOrCreate(
                    ['employee_id' => $employee->id, 'performance_cycle_id' => $cycle->id, 'type' => 'self'],
                    ['review_cycle_id' => null, 'template_id' => $template->id, 'status' => 'draft'],
                );

                // Seed KPI rows for each component
                foreach ($template->components as $component) {
                    $kpi = EmployeeKpi::firstOrCreate(
                        ['employee_id' => $employee->id, 'component_id' => $component->id, 'performance_cycle_id' => $cycle->id],
                        ['assigned_by' => $actor->id, 'status' => 'not_started', 'due_date' => $cycle->end_date],
                    );

                    if ($kpi->wasRecentlyCreated) {
                        $employee->user->notify(new KpiAssignedNotification($kpi));
                    }

                    PerformanceReviewScore::firstOrCreate(
                        ['review_id' => $review->id, 'component_id' => $component->id],
                        ['auto_computed' => $component->isAutoScored()],
                    );
                }

                // Multi-reviewer set: self + team lead + dept head (Phase D)
                $this->participants->createParticipantsFor($review);

                $employee->user->notify(new ReviewCycleStartedNotification($cycle));

                $this->timeline->record(
                    $employee,
                    'review_started',
                    'Performance Review Cycle Started',
                    "Cycle: {$cycle->name}",
                    $review,
                    $actor,
                    visibleToEmployee: true,
                );
            }

            $cycle->update(['status' => 'active']);
        });
    }

    /**
     * Employee submits self-review scores.
     *
     * @param  array<int, array{component_id: int, self_score: float, self_comment: ?string}>  $scores
     */
    public function submitSelfReview(PerformanceReview $review, array $scores, User $actor): void
    {
        $this->assertReviewEditable($review, ['draft', 'in_progress']);

        $participant = $review->participants()->where('reviewer_role', 'self')->first()
            ?? $this->participants->createParticipantsFor($review, notify: false)->firstWhere('reviewer_role', 'self');

        $this->participants->submit($participant, array_map(fn (array $row) => [
            'component_id' => $row['component_id'],
            'score' => $row['self_score'],
            'comment' => $row['self_comment'] ?? null,
        ], $scores));

        $this->timeline->record(
            $review->employee,
            'self_review_submitted',
            'Self review submitted',
            "Submitted for cycle: {$review->performanceCycle?->name}",
            $review,
            $actor,
        );
    }

    /**
     * Manager submits component scores and overall feedback.
     *
     * @param  array<int, array{component_id: int, manager_score: float, manager_comment: ?string}>  $scores
     */
    public function submitManagerReview(PerformanceReview $review, array $scores, string $managerFeedback, User $manager): void
    {
        $this->assertReviewEditable($review, ['submitted']);

        $participant = $review->participants()->where('reviewer_id', $manager->id)->first()
            ?? $review->participants()->where('reviewer_role', 'team_lead')->first()
            ?? $review->participants()->create([
                'reviewer_id' => $manager->id,
                'reviewer_role' => 'team_lead',
                'weight_percent' => ReviewWeightage::weightFor('team_lead', $review->employee?->department_id),
                'status' => 'pending',
            ]);

        $this->participants->submit($participant, array_map(fn (array $row) => [
            'component_id' => $row['component_id'],
            'score' => $row['manager_score'],
            'comment' => $row['manager_comment'] ?? null,
        ], $scores));

        $review->update(['manager_feedback' => $managerFeedback]);

        $this->timeline->record(
            $review->employee,
            'manager_review_submitted',
            'Manager review submitted',
            null,
            $review,
            $manager,
        );
    }

    /**
     * HR validates scores, may adjust ±10%, finalizes scorecard.
     *
     * @param  array<int, array{component_id: int, hr_score: float, hr_comment: ?string}>  $scores
     */
    public function submitHrReview(PerformanceReview $review, array $scores, string $hrComments, User $hr): void
    {
        $this->assertReviewEditable($review, ['manager_reviewed']);

        DB::transaction(function () use ($review, $scores, $hrComments, $hr) {
            foreach ($scores as $row) {
                PerformanceReviewScore::where('review_id', $review->id)
                    ->where('component_id', $row['component_id'])
                    ->update(['hr_score' => $row['hr_score'], 'hr_comment' => $row['hr_comment'] ?? null]);
            }

            $review->update([
                'status' => 'hr_reviewed',
                'hr_comments' => $hrComments,
                'hr_reviewer_id' => $hr->id,
                'hr_submitted_at' => now(),
            ]);
        });
    }

    /**
     * Lock review — immutable after this point.
     */
    public function lockReview(PerformanceReview $review, User $actor): void
    {
        if (! in_array($review->status, ['manager_reviewed', 'hr_reviewed'], true)) {
            throw new \DomainException('Only manager-reviewed or HR-reviewed records can be locked.');
        }

        // Spec Part 3.3: a review is only complete when every participant has
        // submitted. The HR-reviewed stage acts as the explicit HR override.
        if ($review->status !== 'hr_reviewed' && ! $review->allParticipantsSubmitted()) {
            $pending = $review->participants()->where('status', '!=', 'submitted')
                ->with('reviewer')->get()
                ->map(fn ($p) => $p->reviewer?->name ?? $p->roleLabel())->implode(', ');
            throw new \DomainException("Waiting on reviewer submissions: {$pending}. HR review can override.");
        }

        if ($review->performanceCycle) {
            // Run scoring engine to compute final weighted score
            $template = $review->template ?? $review->performanceCycle->template;
            if ($template) {
                $this->scoringEngine->buildScorecard($review->employee, $review->performanceCycle, $template);
            }
        }

        $review->update(['status' => 'locked']);

        $this->timeline->record(
            $review->employee,
            'review_completed',
            'Performance review locked',
            "Grade: {$review->fresh()->gradeLabel()}",
            $review,
            $actor,
        );
    }

    private function assertReviewEditable(PerformanceReview $review, array $allowedStatuses): void
    {
        if ($review->status === 'locked') {
            throw new \DomainException('This review is locked and cannot be edited.');
        }

        if (! in_array($review->status, $allowedStatuses, true)) {
            throw new \DomainException(
                'Review must be in '.implode(' or ', $allowedStatuses)." state. Current: {$review->status}."
            );
        }

        if ($review->cycle && $review->cycle->status === 'closed') {
            throw new \DomainException('Review cycle is closed.');
        }
    }

    private function assertCycleStatus(PerformanceCycle $cycle, string $expected): void
    {
        if ($cycle->status !== $expected) {
            throw new \DomainException("Cycle must be in '{$expected}' status. Current: {$cycle->status}.");
        }
    }

    private function resolveEmployeesForTemplate(PerformanceTemplate $template): Collection
    {
        $query = Employee::query()->where('status', 'active');

        return match ($template->applies_to_type) {
            'department' => $query->where('department_id', $template->applies_to_id)->get(),
            'designation' => $query->where('designation_id', $template->applies_to_id)->get(),
            'branch' => $query->where('office_id', $template->applies_to_id)->get(),
            default => $query->get(),
        };
    }
}
