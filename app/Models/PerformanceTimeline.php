<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'employee_id', 'event_type', 'event_date', 'title', 'description',
    'reference_type', 'reference_id', 'created_by', 'is_visible_to_employee',
])]
class PerformanceTimeline extends Model
{
    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'is_visible_to_employee' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function eventIcon(): string
    {
        return match ($this->event_type) {
            'kpi_assigned' => 'target',
            'review_started', 'self_review_submitted', 'manager_review_submitted', 'review_completed' => 'clipboard-document-check',
            'scorecard_locked' => 'lock-closed',
            'warning_issued', 'warning_acknowledged' => 'exclamation-triangle',
            'pip_created', 'pip_milestone', 'pip_outcome' => 'chart-bar',
            'promotion_recommended', 'promotion_approved' => 'arrow-trending-up',
            'salary_revised' => 'banknotes',
            'transfer' => 'arrows-right-left',
            'award', 'recognition' => 'star',
            default => 'ellipsis-horizontal-circle',
        };
    }

    public function eventColor(): string
    {
        return match ($this->event_type) {
            'promotion_approved', 'review_completed', 'award', 'recognition' => 'green',
            'warning_issued' => 'red',
            'pip_created', 'pip_outcome' => 'orange',
            'salary_revised', 'promotion_recommended' => 'blue',
            default => 'zinc',
        };
    }
}
