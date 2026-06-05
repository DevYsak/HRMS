<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id', 'warning_letter_id', 'manager_id', 'hr_reviewer_id', 'department_head_id',
    'review_period_days', 'start_date', 'end_date',
    'action_plan', 'success_criteria',
    'manager_notes', 'hr_notes', 'employee_comments',
    'current_reviewer_stage', 'status', 'outcome', 'outcome_date',
])]
class PipRecord extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'outcome_date' => 'date',
            'review_period_days' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function warningLetter(): BelongsTo
    {
        return $this->belongsTo(WarningLetter::class, 'warning_letter_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
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
        return $this->hasMany(PipGoal::class, 'pip_record_id');
    }

    public function overallProgress(): float
    {
        $goals = $this->goals;
        if ($goals->isEmpty()) {
            return 0.0;
        }

        return (float) round($goals->avg('progress_percent'), 2);
    }

    public function daysRemaining(): int
    {
        return max(0, now()->diffInDays($this->end_date, false));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'active' => 'Active',
            'under_review' => 'Under Review',
            'extended' => 'Extended',
            'successful' => 'Successful',
            'failed' => 'Failed',
            'escalated' => 'Escalated',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'active' => 'warning',
            'successful' => 'on_time',
            'failed', 'escalated' => 'terminated',
            default => 'onboarding',
        };
    }
}
