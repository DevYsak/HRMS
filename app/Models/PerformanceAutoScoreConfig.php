<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'template_id', 'component_id',
    'source_type', 'formula',
    'full_score_at', 'zero_score_at',
])]
class PerformanceAutoScoreConfig extends Model
{
    protected function casts(): array
    {
        return [
            'full_score_at' => 'float',
            'zero_score_at' => 'float',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PerformanceTemplate::class, 'template_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PerformanceComponent::class, 'component_id');
    }

    /** Compute score (0–100) for a given raw value using this config's formula. */
    public function compute(float $rawValue): float
    {
        $full = $this->full_score_at;
        $zero = $this->zero_score_at;

        if ($this->formula === 'threshold') {
            return $rawValue >= $full ? 100.0 : 0.0;
        }

        if ($this->formula === 'inverse') {
            // Lower raw value = higher score (e.g. late_marks: fewer = better)
            if ($rawValue <= $full) {
                return 100.0;
            }
            if ($rawValue >= $zero) {
                return 0.0;
            }

            return (float) round(($zero - $rawValue) / ($zero - $full) * 100, 2);
        }

        // linear (default)
        if ($rawValue >= $full) {
            return 100.0;
        }
        if ($rawValue <= $zero) {
            return 0.0;
        }

        return (float) round(($rawValue - $zero) / ($full - $zero) * 100, 2);
    }
}
