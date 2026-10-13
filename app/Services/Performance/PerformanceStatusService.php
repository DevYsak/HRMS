<?php

namespace App\Services\Performance;

use App\Models\Employee;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Review status counts, the same everywhere (Team View, HR dashboard,
 * Department Overview, Performance, the AI assistant).
 *
 * One state per employee per cycle, read from the review's participants and
 * the cycle — never from `performance_reviews.status` alone, because a
 * department head's or additional reviewer's submission never moves it:
 *
 *  - not_started             no review yet, or the cycle has not opened
 *  - self_review_pending     the employee's self-assessment is outstanding
 *  - manager_review_pending  self done (or not needed); a reviewer, HR or the lock is outstanding
 *  - completed               the review is locked (final score set by KpiScoringEngine)
 *  - overdue                 a flag on a pending review whose current stage deadline has passed
 *
 * Scores are never recalculated here: the average uses the final score that
 * {@see KpiScoringEngine} wrote when each review was locked.
 */
class PerformanceStatusService
{
    public const NOT_STARTED = 'not_started';

    public const SELF_PENDING = 'self_review_pending';

    public const MANAGER_PENDING = 'manager_review_pending';

    public const COMPLETED = 'completed';

    public const OVERDUE = 'overdue';

    public const LABELS = [
        self::NOT_STARTED => 'Not Started',
        self::SELF_PENDING => 'Self Review Pending',
        self::MANAGER_PENDING => 'Manager Review Pending',
        self::COMPLETED => 'Completed',
        self::OVERDUE => 'Overdue',
    ];

    /**
     * The cycle every dashboard reports on: the active cycle running today,
     * else the most recent active, completed or locked cycle. Mirrored legacy
     * cycles (component-less LEGACY template) never win.
     */
    public function currentCycle(?CarbonInterface $today = null): ?PerformanceCycle
    {
        $today ??= now();

        $base = fn () => PerformanceCycle::query()
            ->whereIn('status', ['active', 'self_review', 'manager_review', 'hr_review', 'completed', 'locked'])
            ->where(fn ($q) => $q->whereNull('template_id')->orWhereHas('template', fn ($t) => $t->where('code', '!=', LegacyCycleMigrator::LEGACY_TEMPLATE_CODE)->orWhereNull('code')));

        return $base()->where('status', '!=', 'completed')->where('status', '!=', 'locked')
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
            ->orderByDesc('start_date')->first()
            ?? $base()->orderByDesc('start_date')->first();
    }

    /**
     * @return array{state: string, label: string, overdue: bool, due: ?CarbonInterface}
     */
    public function statusFor(PerformanceReview $review, ?CarbonInterface $today = null): array
    {
        $today ??= now();
        $cycle = $review->performanceCycle;

        if ($review->status === 'locked') {
            return $this->result(self::COMPLETED, false, null);
        }

        if ($cycle && ($cycle->status === 'draft' || ($cycle->start_date && $today->lt($cycle->start_date)))) {
            return $this->result(self::NOT_STARTED, false, null);
        }

        $participants = $review->relationLoaded('participants') ? $review->participants : $review->participants()->get();
        $self = $participants->firstWhere('reviewer_role', 'self');

        $selfDone = $self
            ? $self->status === 'submitted'
            : ($review->self_submitted_at !== null || ! in_array($review->status, ['draft', 'pending', 'in_progress'], true));

        $state = $selfDone ? self::MANAGER_PENDING : self::SELF_PENDING;

        $due = $state === self::SELF_PENDING
            ? ($cycle?->self_review_deadline ?? $cycle?->end_date)
            : ($cycle?->manager_review_deadline ?? $cycle?->hr_review_deadline ?? $cycle?->end_date);

        return $this->result($state, $due !== null && $today->copy()->startOfDay()->gt($due), $due);
    }

    /**
     * Counts over a set of employees for one cycle — one state per employee.
     *
     * @param  Collection<int, int>|array<int, int>  $employeeIds
     * @return array{cycle: ?PerformanceCycle, total: int, not_started: int, self_review_pending: int, manager_review_pending: int, completed: int, overdue: int, pending: int, avg_score: ?float, by_employee: array<int, string>}
     */
    public function summary(Collection|array $employeeIds, ?PerformanceCycle $cycle = null, ?CarbonInterface $today = null): array
    {
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values();
        $cycle ??= $this->currentCycle($today);

        $summary = [
            'cycle' => $cycle, 'total' => $ids->count(),
            self::NOT_STARTED => 0, self::SELF_PENDING => 0, self::MANAGER_PENDING => 0, self::COMPLETED => 0,
            self::OVERDUE => 0, 'pending' => 0, 'avg_score' => null, 'by_employee' => [],
        ];

        if ($ids->isEmpty()) {
            return $summary;
        }

        $reviews = $cycle
            ? PerformanceReview::where('performance_cycle_id', $cycle->id)->whereIn('employee_id', $ids)
                ->with(['participants', 'performanceCycle'])->get()->groupBy('employee_id')
            : collect();

        $scores = [];

        foreach ($ids as $employeeId) {
            $review = $this->primaryReview($reviews->get($employeeId, collect()));
            $status = $review ? $this->statusFor($review, $today) : $this->result(self::NOT_STARTED, false, null);

            $summary[$status['state']]++;
            $summary['by_employee'][$employeeId] = $status['overdue'] ? self::OVERDUE : $status['state'];

            if ($status['overdue']) {
                $summary[self::OVERDUE]++;
            }

            if ($status['state'] === self::COMPLETED && $review->final_score !== null) {
                $scores[] = (float) $review->final_score;
            }
        }

        $summary['pending'] = $summary[self::SELF_PENDING] + $summary[self::MANAGER_PENDING];
        $summary['avg_score'] = $scores === [] ? null : round(array_sum($scores) / count($scores), 1);

        return $summary;
    }

    /**
     * Per-department counts for the Department Health and Performance
     * Overview tables.
     *
     * @param  array<int, int>|null  $employeeIds  null = everyone working
     * @return Collection<int, array<string, mixed>>
     */
    public function byDepartment(?array $employeeIds, ?PerformanceCycle $cycle = null): Collection
    {
        $cycle ??= $this->currentCycle();

        return Employee::query()
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds))
            ->with('department:id,name')
            ->get(['id', 'department_id'])
            ->groupBy('department_id')
            ->map(fn (Collection $people) => ['department' => $people->first()->department?->name ?? 'No department', 'department_id' => $people->first()->department_id]
                + $this->summary($people->pluck('id'), $cycle))
            ->sortBy('department')
            ->values();
    }

    /**
     * One review per employee: the locked one if any, else the self review,
     * else the first (legacy cycles can hold a self and a manager row).
     *
     * @param  Collection<int, PerformanceReview>  $reviews
     */
    /** The review that represents the employee in a cycle: locked, else the self review, else any. */
    public function primaryReview(Collection $reviews): ?PerformanceReview
    {
        return $reviews->firstWhere('status', 'locked')
            ?? $reviews->firstWhere('type', 'self')
            ?? $reviews->first();
    }

    /** @return array{state: string, label: string, overdue: bool, due: ?CarbonInterface} */
    private function result(string $state, bool $overdue, ?CarbonInterface $due): array
    {
        return ['state' => $state, 'label' => $overdue ? self::LABELS[self::OVERDUE] : self::LABELS[$state], 'overdue' => $overdue, 'due' => $due];
    }
}
