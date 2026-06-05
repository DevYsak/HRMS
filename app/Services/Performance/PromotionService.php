<?php

namespace App\Services\Performance;

use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\PromotionRecommendation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PromotionService
{
    public function __construct(private readonly TimelineService $timeline) {}

    /**
     * Manager creates a recommendation.
     *
     * @param  array{recommendation_type: string, current_role: ?string, proposed_role: ?string, current_salary: ?float, proposed_salary: ?float, bonus_amount: ?float, target_department_id: ?int, justification: string, effective_date: ?string}  $data
     */
    public function recommend(Employee $employee, array $data, User $manager, ?PerformanceReview $review = null): PromotionRecommendation
    {
        return DB::transaction(function () use ($employee, $data, $manager, $review) {
            $recommendation = PromotionRecommendation::create([
                'employee_id' => $employee->id,
                'recommended_by' => $manager->id,
                'performance_review_id' => $review?->id,
                'recommendation_type' => $data['recommendation_type'],
                'current_role' => $data['current_role'] ?? null,
                'proposed_role' => $data['proposed_role'] ?? null,
                'current_salary' => $data['current_salary'] ?? null,
                'proposed_salary' => $data['proposed_salary'] ?? null,
                'bonus_amount' => $data['bonus_amount'] ?? null,
                'target_department_id' => $data['target_department_id'] ?? null,
                'justification' => $data['justification'],
                'current_reviewer_stage' => 'hr',
                'status' => 'pending_hr',
                'effective_date' => $data['effective_date'] ?? null,
            ]);

            $this->timeline->record(
                $employee,
                'promotion_recommended',
                $recommendation->recommendationTypeLabel().' Recommended',
                $data['justification'],
                $recommendation,
                $manager,
                visibleToEmployee: false,
            );

            return $recommendation;
        });
    }

    /**
     * Advance approval to the next stage.
     */
    public function approve(PromotionRecommendation $recommendation, User $approver, string $comments): void
    {
        $nextStatus = match ($recommendation->current_reviewer_stage) {
            'hr' => 'pending_dept_head',
            'dept_head' => 'pending_super_admin',
            'super_admin' => 'approved',
            default => throw new \DomainException('Invalid approval stage.'),
        };

        $nextStage = match ($recommendation->current_reviewer_stage) {
            'hr' => 'dept_head',
            'dept_head' => 'super_admin',
            default => 'super_admin',
        };

        $commentField = match ($recommendation->current_reviewer_stage) {
            'hr' => 'hr_comments',
            'dept_head' => 'dept_head_comments',
            'super_admin' => 'super_admin_comments',
        };

        $recommendation->update([
            'status' => $nextStatus,
            'current_reviewer_stage' => $nextStage,
            $commentField => $comments,
        ]);

        if ($nextStatus === 'approved') {
            $this->timeline->record(
                $recommendation->employee,
                'promotion_approved',
                $recommendation->recommendationTypeLabel().' Approved',
                'Effective: '.($recommendation->effective_date?->toFormattedDateString() ?? 'TBD'),
                $recommendation,
                $approver,
                visibleToEmployee: true,
            );
        }
    }

    /**
     * Reject recommendation at any stage.
     */
    public function reject(PromotionRecommendation $recommendation, User $rejector, string $reason): void
    {
        if ($recommendation->status === 'approved') {
            throw new \DomainException('Approved recommendations cannot be rejected.');
        }

        $commentField = match ($recommendation->current_reviewer_stage) {
            'hr' => 'hr_comments',
            'dept_head' => 'dept_head_comments',
            'super_admin' => 'super_admin_comments',
            default => 'hr_comments',
        };

        $recommendation->update([
            'status' => 'rejected',
            $commentField => $reason,
        ]);
    }
}
