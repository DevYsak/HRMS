<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'phase', 'title', 'description',
    'category', 'owner_role', 'is_completed', 'completed_at', 'completed_by',
    'due_date', 'sort_order', 'status', 'blocked_reason', 'auto_trigger', 'template_task_id',
])]
class OnboardingTask extends Model
{
    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function templateTask(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplateTask::class, 'template_task_id');
    }

    /** @return Builder<static> */
    public function scopeOnboarding(Builder $query): Builder
    {
        return $query->where('phase', 'onboarding');
    }

    /** @return Builder<static> */
    public function scopeOffboarding(Builder $query): Builder
    {
        return $query->where('phase', 'offboarding');
    }

    /** @return Builder<static> */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('is_completed', false);
    }

    /** @return Builder<static> */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('is_completed', false)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString());
    }

    /** @return Builder<static> */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @return array{label: string, color: string, icon: string}
     */
    public function statusBadge(): array
    {
        return match ($this->status) {
            'completed' => ['label' => 'Completed',   'color' => 'green',  'icon' => 'check-circle'],
            'in_progress' => ['label' => 'In Progress', 'color' => 'blue',   'icon' => 'arrow-path'],
            'overdue' => ['label' => 'Overdue',     'color' => 'red',    'icon' => 'exclamation-triangle'],
            'blocked' => ['label' => 'Blocked',     'color' => 'orange', 'icon' => 'x-circle'],
            default => ['label' => 'Pending',     'color' => 'zinc',   'icon' => 'clock'],
        };
    }
}
