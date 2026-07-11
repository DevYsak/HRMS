<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's increment proposal in a cycle (v4 Phase E) — carries the
 * full calibration trail so every % is explainable.
 */
#[Fillable([
    'increment_cycle_id', 'employee_id', 'annual_raw_score', 'quarters_counted',
    'calibrated_z', 'band', 'band_overridden', 'override_reason',
    'current_gross', 'proposed_percent', 'proposed_amount', 'new_gross',
    'promotion_flag', 'new_designation', 'status', 'proposed_by', 'approved_by',
    'remarks', 'letter_path',
])]
class IncrementProposal extends Model
{
    public const BAND_LABELS = [
        'A' => 'Outstanding',
        'B' => 'Exceeds Expectations',
        'C' => 'Meets Expectations',
        'D' => 'Below Expectations',
        'E' => 'Needs Improvement',
    ];

    protected function casts(): array
    {
        return [
            'annual_raw_score' => 'float',
            'quarters_counted' => 'integer',
            'calibrated_z' => 'float',
            'band_overridden' => 'boolean',
            'current_gross' => 'float',
            'proposed_percent' => 'float',
            'proposed_amount' => 'float',
            'new_gross' => 'float',
            'promotion_flag' => 'boolean',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(IncrementCycle::class, 'increment_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function bandLabel(): string
    {
        return $this->band
            ? $this->band.' — '.(self::BAND_LABELS[$this->band] ?? '')
            : 'Insufficient data';
    }

    /** Eligible = has a band; insufficient-data rows need a manual decision. */
    public function isEligible(): bool
    {
        return $this->band !== null;
    }
}
