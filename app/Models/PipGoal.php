<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pip_record_id', 'title', 'description',
    'target_date', 'weightage', 'progress_percent',
    'status', 'manager_notes',
])]
class PipGoal extends Model
{
    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'weightage' => 'float',
            'progress_percent' => 'float',
        ];
    }

    public function pipRecord(): BelongsTo
    {
        return $this->belongsTo(PipRecord::class, 'pip_record_id');
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
