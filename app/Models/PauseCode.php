<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'label', 'description', 'is_active'])]
class PauseCode extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return Builder<static> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
