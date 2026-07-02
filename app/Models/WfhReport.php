<?php

namespace App\Models;

use Database\Factories\WfhReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An end-of-day work-from-home report: what was done, achievements,
 * blockers and tomorrow's plan, with an optional manager comment.
 * One per employee per day.
 */
#[Fillable([
    'employee_id', 'date', 'work_summary', 'achievements', 'blockers',
    'tomorrow_plan', 'manager_comment', 'reviewed_by', 'reviewed_at',
])]
class WfhReport extends Model
{
    /** @use HasFactory<WfhReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }
}
