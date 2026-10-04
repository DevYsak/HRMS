<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['employee_id', 'salary_component_id', 'amount', 'effective_from', 'effective_to'])]
class EmployeeSalary extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * Scope to salary rows effective on a given date.
     * Rows with null effective_from are treated as "always active from the beginning".
     * Rows with null effective_to are treated as "open-ended / no expiry".
     */
    public function scopeEffectiveOn(Builder $query, Carbon $date): void
    {
        // effective_from/to are DATE columns: compare dates, not datetimes. A
        // cycle end of "31 Jul 23:59:59" against a row ending "2026-07-31"
        // matched neither the old row nor the next one starting 1 Aug, so the
        // month before a revision paid no salary at all.
        $day = $date->toDateString();

        $query->where(function ($q) use ($day) {
            $q->whereNull('effective_from')->orWhere('effective_from', '<=', $day);
        })->where(function ($q) use ($day) {
            $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day);
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
