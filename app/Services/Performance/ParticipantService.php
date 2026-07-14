<?php

namespace App\Services\Performance;

use App\Models\PerformanceReview;
use App\Models\PerformanceReviewScore;
use App\Models\ReviewParticipant;
use App\Models\ReviewWeightage;
use App\Models\User;
use App\Notifications\ReviewParticipantAssignedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Multi-reviewer participation (v4 Phase D / spec Part 3.3).
 *
 * Participants: self + team lead + department head (each resolved from the
 * team structure), plus optional additional reviewers. Weights come from
 * review_weightages (department override → company default → spec defaults
 * 20/50/30) and are re-normalised to total 100 across the rungs that
 * actually resolve. HR remains a validation stage on the review itself, not
 * a weighted participant.
 */
class ParticipantService
{
    /**
     * Create the participant set for a review. Idempotent — existing
     * participants are kept and missing rungs added.
     *
     * @return Collection<int, ReviewParticipant>
     */
    public function createParticipantsFor(PerformanceReview $review, bool $notify = true): Collection
    {
        $employee = $review->employee;
        $departmentId = $employee->department_id;

        /** @var array<string, ?User> $rungs */
        $rungs = ['self' => $employee->user];

        $team = $employee->activeTeam();
        $lead = $team?->teamLead;
        if ($lead && $lead->id !== $employee->id && $lead->user) {
            $rungs['team_lead'] = $lead->user;
        } elseif ($employee->manager) {
            // No team (or the employee leads it) — the reporting manager rates.
            $rungs['team_lead'] = $employee->manager;
        }

        $head = $employee->department?->head;
        if ($head && $head->id !== $employee->user_id && $head->id !== ($rungs['team_lead']->id ?? null)) {
            $rungs['department_head'] = $head;
        }

        $rungs = array_filter($rungs);

        $weights = [];
        foreach ($rungs as $role => $user) {
            $weights[$role] = ReviewWeightage::weightFor($role, $departmentId);
        }
        $weights = $this->normalise($weights);

        return DB::transaction(function () use ($review, $rungs, $weights, $notify) {
            $participants = collect();

            foreach ($rungs as $role => $user) {
                $participant = ReviewParticipant::firstOrCreate(
                    ['performance_review_id' => $review->id, 'reviewer_id' => $user->id],
                    ['reviewer_role' => $role, 'weight_percent' => $weights[$role], 'status' => 'pending'],
                );

                if ($participant->wasRecentlyCreated && $notify && $role !== 'self') {
                    $user->notify(new ReviewParticipantAssignedNotification($participant));
                }

                $participants->push($participant);
            }

            return $participants;
        });
    }

    /**
     * Add an extra reviewer (e.g. a cross-functional project manager) with a
     * given weight; existing participants shrink proportionally so the total
     * stays 100 (spec Part 3.3).
     */
    public function addAdditionalReviewer(PerformanceReview $review, User $reviewer, float $weightPercent): ReviewParticipant
    {
        if ($weightPercent <= 0 || $weightPercent >= 100) {
            throw new \DomainException('Additional reviewer weight must be between 0 and 100.');
        }

        if ($review->participants()->where('reviewer_id', $reviewer->id)->exists()) {
            throw new \DomainException('This user is already a participant on the review.');
        }

        return DB::transaction(function () use ($review, $reviewer, $weightPercent) {
            $existing = $review->participants()->lockForUpdate()->get();
            $currentTotal = (float) $existing->sum('weight_percent') ?: 100.0;
            $factor = (100 - $weightPercent) / $currentTotal;

            foreach ($existing as $participant) {
                $participant->update(['weight_percent' => round($participant->weight_percent * $factor, 2)]);
            }

            $participant = ReviewParticipant::create([
                'performance_review_id' => $review->id,
                'reviewer_id' => $reviewer->id,
                'reviewer_role' => 'additional',
                'weight_percent' => $weightPercent,
                'status' => 'pending',
            ]);

            $reviewer->notify(new ReviewParticipantAssignedNotification($participant));

            return $participant;
        });
    }

    /**
     * A participant submits their independent scores for the review's
     * components. Self and team-lead submissions are mirrored into the wide
     * columns on performance_review_scores so existing dashboards keep
     * working; the composite is computed from participant rows at lock time.
     *
     * @param  array<int, array{component_id: int, score: float, comment: ?string}>  $scores
     */
    public function submit(ReviewParticipant $participant, array $scores): void
    {
        $review = $participant->review;

        if ($review->status === 'locked') {
            throw new \DomainException('This review is locked and cannot be edited.');
        }

        if ($participant->isSubmitted()) {
            throw new \DomainException('You have already submitted this review.');
        }

        DB::transaction(function () use ($participant, $review, $scores) {
            foreach ($scores as $row) {
                $participant->scores()->updateOrCreate(
                    ['component_id' => $row['component_id']],
                    ['score' => $row['score'], 'comment' => $row['comment'] ?? null],
                );

                $this->mirrorWideColumns($review, $participant->reviewer_role, $row);
            }

            $participant->update(['status' => 'submitted', 'submitted_at' => now()]);

            // Keep the review's stage timestamps/status in step with the
            // roles the existing workflow understands.
            if ($participant->reviewer_role === 'self') {
                $review->update([
                    'status' => $review->status === 'draft' ? 'submitted' : $review->status,
                    'submitted_at' => $review->submitted_at ?? now(),
                    'self_submitted_at' => now(),
                ]);
            } elseif ($participant->reviewer_role === 'team_lead') {
                $review->update([
                    'status' => in_array($review->status, ['draft', 'submitted'], true) ? 'manager_reviewed' : $review->status,
                    'manager_submitted_at' => now(),
                ]);
            }
        });
    }

    /** Mirror a participant's component score into the legacy wide columns. */
    private function mirrorWideColumns(PerformanceReview $review, string $role, array $row): void
    {
        $columns = match ($role) {
            'self' => ['self_score' => $row['score'], 'self_comment' => $row['comment'] ?? null],
            'team_lead' => ['manager_score' => $row['score'], 'manager_comment' => $row['comment'] ?? null],
            default => null,
        };

        if ($columns === null) {
            return;
        }

        PerformanceReviewScore::updateOrCreate(
            ['review_id' => $review->id, 'component_id' => $row['component_id']],
            $columns,
        );
    }

    /**
     * Backfill: turn legacy wide-column scores into participant rows for
     * reviews that predate Phase D. Idempotent.
     *
     * @return array{participants_created: int, scores_copied: int}
     */
    public function migrateWideScores(): array
    {
        $created = 0;
        $copied = 0;

        PerformanceReview::query()
            ->whereDoesntHave('participants')
            ->whereHas('componentScores', fn ($q) => $q->whereNotNull('self_score')->orWhereNotNull('manager_score'))
            ->with(['employee.user', 'reviewer.user', 'componentScores'])
            ->chunkById(100, function ($reviews) use (&$created, &$copied) {
                foreach ($reviews as $review) {
                    $map = [];

                    if ($review->employee?->user) {
                        $map['self'] = $review->employee->user;
                    }
                    if ($review->reviewer?->user) {
                        $map['team_lead'] = $review->reviewer->user;
                    }

                    $weights = $this->normalise(array_map(
                        fn ($role) => ReviewWeightage::weightFor($role, $review->employee?->department_id),
                        array_combine(array_keys($map), array_keys($map)),
                    ));

                    foreach ($map as $role => $user) {
                        $scoreColumn = $role === 'self' ? 'self_score' : 'manager_score';
                        $commentColumn = $role === 'self' ? 'self_comment' : 'manager_comment';
                        $submittedAt = $role === 'self' ? $review->self_submitted_at : $review->manager_submitted_at;

                        $rows = $review->componentScores->whereNotNull($scoreColumn);
                        if ($rows->isEmpty()) {
                            continue;
                        }

                        $participant = ReviewParticipant::firstOrCreate(
                            ['performance_review_id' => $review->id, 'reviewer_id' => $user->id],
                            [
                                'reviewer_role' => $role,
                                'weight_percent' => $weights[$role],
                                'status' => 'submitted',
                                'submitted_at' => $submittedAt ?? $review->updated_at,
                            ],
                        );

                        if (! $participant->wasRecentlyCreated) {
                            continue;
                        }
                        $created++;

                        foreach ($rows as $wide) {
                            $participant->scores()->create([
                                'component_id' => $wide->component_id,
                                'score' => $wide->{$scoreColumn},
                                'comment' => $wide->{$commentColumn},
                            ]);
                            $copied++;
                        }
                    }
                }
            });

        return ['participants_created' => $created, 'scores_copied' => $copied];
    }

    /**
     * Scale weights so they total 100.
     *
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    private function normalise(array $weights): array
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            $count = max(1, count($weights));

            return array_map(fn () => round(100 / $count, 2), $weights);
        }

        return array_map(fn ($w) => round($w / $total * 100, 2), $weights);
    }
}
