<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id', 'recommended_by', 'performance_review_id',
    'recommendation_type', 'current_role', 'proposed_role',
    'current_salary', 'proposed_salary', 'bonus_amount',
    'target_department_id', 'justification',
    'current_reviewer_stage', 'status',
    'hr_comments', 'dept_head_comments', 'super_admin_comments',
    'effective_date',
])]
class PromotionRecommendation extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'current_salary' => 'float',
            'proposed_salary' => 'float',
            'bonus_amount' => 'float',
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recommendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommended_by');
    }

    public function performanceReview(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'performance_review_id');
    }

    public function targetDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'target_department_id');
    }

    /** Documents attached to this recommendation (offer letter, approval memo, etc.). */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function incrementPercent(): ?float
    {
        if ($this->current_salary && $this->proposed_salary && $this->current_salary > 0) {
            return round(($this->proposed_salary - $this->current_salary) / $this->current_salary * 100, 2);
        }

        return null;
    }

    public function recommendationTypeLabel(): string
    {
        return match ($this->recommendation_type) {
            'promotion' => 'Promotion',
            'salary_increment' => 'Salary Increment',
            'bonus' => 'Bonus',
            'role_change' => 'Role Change',
            'dept_transfer' => 'Department Transfer',
            default => ucfirst($this->recommendation_type),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'pending_hr' => 'Pending HR',
            'pending_dept_head' => 'Pending Dept Head',
            'pending_super_admin' => 'Pending Super Admin',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'approved' => 'on_time',
            'rejected' => 'terminated',
            'draft' => 'onboarding',
            default => 'warning',
        };
    }
}
