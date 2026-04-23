<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['review_cycle_id', 'employee_id', 'reviewer_id', 'type', 'overall_rating', 'promotion_recommended', 'strengths', 'improvements', 'comments', 'manager_feedback', 'status', 'submitted_at'])]
class PerformanceReview extends Model
{
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'overall_rating' => 'integer',
            'promotion_recommended' => 'boolean',
        ];
    }

    public function cycle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    public function goals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReviewGoal::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'submitted' => 'Submitted (Self)',
            'manager_reviewed' => 'Manager Reviewed',
            'locked' => 'Locked',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'submitted' => 'on_time',
            'in_progress' => 'manager',
            default => 'onboarding',
        };
    }
}
