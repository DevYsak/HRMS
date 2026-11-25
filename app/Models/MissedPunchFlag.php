<?php

namespace App\Models;

use Database\Factories\MissedPunchFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee over the weekly missed-punch allowance in one Monday–Friday
 * week, and HR's review of it. See MissedPunchAllowance.
 */
#[Fillable(['employee_id', 'week_start', 'missed_count', 'allowance', 'flagged_at', 'reviewed_by', 'reviewed_at', 'review_note'])]
class MissedPunchFlag extends Model
{
    /** @use HasFactory<MissedPunchFlagFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'flagged_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'missed_count' => 'integer',
            'allowance' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Flags HR has not reviewed yet. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('reviewed_at');
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }
}
