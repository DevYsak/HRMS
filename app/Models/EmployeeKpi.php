<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'component_id', 'performance_cycle_id', 'assigned_by',
    'target_value', 'achieved_value', 'progress_percent',
    'status', 'notes', 'due_date',
])]
class EmployeeKpi extends Model
{
    protected function casts(): array
    {
        return [
            'target_value' => 'float',
            'achieved_value' => 'float',
            'progress_percent' => 'float',
            'due_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PerformanceComponent::class, 'component_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'achieved' => 'on_time',
            'in_progress' => 'warning',
            'missed' => 'terminated',
            default => 'onboarding',
        };
    }
}
