<?php

namespace App\Models;

use App\Enums\StatutoryRuleType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned statutory rate set in force over a date window.
 *
 * Rows are never edited in place once a payroll has consumed them — close the
 * current row with an effective_to and open a successor, so historical payslips
 * keep resolving the rates they were computed under.
 */
#[Fillable([
    'type', 'jurisdiction', 'regime', 'effective_from', 'effective_to',
    'config', 'is_active', 'label', 'notes', 'updated_by',
])]
class StatutoryRule extends Model
{
    protected function casts(): array
    {
        return [
            'type' => StatutoryRuleType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Rules whose effective window contains $date and which are switched on. */
    public function scopeInForceOn(Builder $query, CarbonInterface $date): void
    {
        $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            });
    }

    /** True when this row has no closing date and so applies indefinitely. */
    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
    }
}
