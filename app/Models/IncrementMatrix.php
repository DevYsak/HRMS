<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Band → increment % range for one cycle (v4 Phase E). */
#[Fillable(['increment_cycle_id', 'band', 'min_percent', 'max_percent', 'default_percent'])]
class IncrementMatrix extends Model
{
    protected function casts(): array
    {
        return [
            'min_percent' => 'float',
            'max_percent' => 'float',
            'default_percent' => 'float',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(IncrementCycle::class, 'increment_cycle_id');
    }
}
