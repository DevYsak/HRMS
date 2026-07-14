<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One increment round per Conexus financial year (v4 Phase E). Statuses:
 * draft → calibration → proposed → approved → applied.
 */
#[Fillable([
    'financial_year', 'effective_date', 'budget_percent', 'quarter_weights',
    'status', 'created_by', 'approved_by', 'approved_at',
])]
class IncrementCycle extends Model
{
    /** Default band matrix seeded on cycle creation (spec Part 4.3). */
    public const DEFAULT_MATRIX = [
        'A' => ['min' => 12, 'max' => 18, 'default' => 15],
        'B' => ['min' => 8, 'max' => 12, 'default' => 10],
        'C' => ['min' => 5, 'max' => 8, 'default' => 6],
        'D' => ['min' => 0, 'max' => 4, 'default' => 2],
        'E' => ['min' => 0, 'max' => 0, 'default' => 0],
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'budget_percent' => 'float',
            'quarter_weights' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function matrix(): HasMany
    {
        return $this->hasMany(IncrementMatrix::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(IncrementProposal::class);
    }

    public function matrixFor(string $band): ?IncrementMatrix
    {
        return $this->matrix->firstWhere('band', $band);
    }

    /** Total annual budget in ₹ = budget_percent × current annual payroll of eligible employees. */
    public function budgetAmount(): float
    {
        $annualPayroll = (float) $this->proposals()->sum('current_gross') * 12;

        return round($annualPayroll * $this->budget_percent / 100, 2);
    }

    /** Committed annual cost of currently proposed increments. */
    public function committedAmount(): float
    {
        return round((float) $this->proposals()->where('status', '!=', 'rejected')->sum('proposed_amount') * 12, 2);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'calibration', 'proposed'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'calibration' => 'Calibration',
            'proposed' => 'Proposed',
            'approved' => 'Approved',
            'applied' => 'Applied',
            default => ucfirst($this->status),
        };
    }
}
