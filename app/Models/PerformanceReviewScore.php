<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'review_id', 'component_id',
    'self_score', 'manager_score', 'hr_score', 'final_score', 'weighted_score',
    'self_comment', 'manager_comment', 'hr_comment',
    'auto_computed',
])]
class PerformanceReviewScore extends Model
{
    protected function casts(): array
    {
        return [
            'self_score' => 'float',
            'manager_score' => 'float',
            'hr_score' => 'float',
            'final_score' => 'float',
            'weighted_score' => 'float',
            'auto_computed' => 'boolean',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'review_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PerformanceComponent::class, 'component_id');
    }

    public function effectiveScore(): float
    {
        return (float) ($this->hr_score ?? $this->manager_score ?? $this->self_score ?? 0);
    }
}
