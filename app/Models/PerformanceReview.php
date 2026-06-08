<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'review_cycle_id', 'performance_cycle_id', 'template_id',
    'employee_id', 'reviewer_id', 'hr_reviewer_id', 'department_head_id',
    'type', 'overall_rating', 'final_score', 'grade',
    'attendance_score', 'punctuality_score', 'leave_impact_score',
    'promotion_recommended', 'strengths', 'improvements',
    'comments', 'manager_feedback', 'hr_comments', 'dept_head_comments',
    'status', 'submitted_at', 'self_submitted_at', 'manager_submitted_at', 'hr_submitted_at',
])]
class PerformanceReview extends Model
{
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'self_submitted_at' => 'datetime',
            'manager_submitted_at' => 'datetime',
            'hr_submitted_at' => 'datetime',
            'overall_rating' => 'integer',
            'final_score' => 'float',
            'attendance_score' => 'float',
            'punctuality_score' => 'float',
            'leave_impact_score' => 'float',
            'promotion_recommended' => 'boolean',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    public function performanceCycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PerformanceTemplate::class, 'template_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    public function hrReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_reviewer_id');
    }

    public function departmentHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'department_head_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(ReviewGoal::class);
    }

    public function componentScores(): HasMany
    {
        return $this->hasMany(PerformanceReviewScore::class, 'review_id');
    }

    /** Documents attached to this review (signed scorecard, manager notes, etc.). */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function promotionRecommendations(): HasMany
    {
        return $this->hasMany(PromotionRecommendation::class, 'performance_review_id');
    }

    public function gradeLabel(): string
    {
        return match ($this->grade) {
            'a_plus' => 'A+ Outstanding',
            'a' => 'A Excellent',
            'b' => 'B Good',
            'c' => 'C Needs Improvement',
            'd' => 'D Critical',
            default => 'Not Graded',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'submitted' => 'Submitted (Self)',
            'manager_reviewed' => 'Manager Reviewed',
            'hr_reviewed' => 'HR Reviewed',
            'locked' => 'Locked',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'submitted' => 'on_time',
            'manager_reviewed', 'hr_reviewed' => 'warning',
            'locked' => 'terminated',
            default => 'onboarding',
        };
    }
}
