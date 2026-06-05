<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'template_id', 'cycle_type',
    'start_date', 'end_date',
    'self_review_deadline', 'manager_review_deadline', 'hr_review_deadline',
    'status', 'created_by',
])]
class PerformanceCycle extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'self_review_deadline' => 'date',
            'manager_review_deadline' => 'date',
            'hr_review_deadline' => 'date',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PerformanceTemplate::class, 'template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class, 'performance_cycle_id');
    }

    public function employeeKpis(): HasMany
    {
        return $this->hasMany(EmployeeKpi::class, 'performance_cycle_id');
    }

    public function scorecards(): HasMany
    {
        return $this->hasMany(EmployeeScorecard::class, 'performance_cycle_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'active' => 'Active',
            'self_review' => 'Self Review',
            'manager_review' => 'Manager Review',
            'hr_review' => 'HR Review',
            'completed' => 'Completed',
            'locked' => 'Locked',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'active', 'self_review' => 'on_time',
            'manager_review', 'hr_review' => 'warning',
            'completed', 'locked' => 'terminated',
            default => 'onboarding',
        };
    }

    public function isSelfReviewOpen(): bool
    {
        return in_array($this->status, ['active', 'self_review'], true)
            && ($this->self_review_deadline === null || now()->lte($this->self_review_deadline));
    }

    public function isManagerReviewOpen(): bool
    {
        return $this->status === 'manager_review'
            && ($this->manager_review_deadline === null || now()->lte($this->manager_review_deadline));
    }
}
