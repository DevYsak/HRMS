<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['template_id', 'name', 'code', 'color', 'description', 'sort_order'])]
class PerformanceCategory extends Model
{
    use SoftDeletes;

    public function template(): BelongsTo
    {
        return $this->belongsTo(PerformanceTemplate::class, 'template_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(PerformanceComponent::class, 'category_id')->orderBy('sort_order');
    }

    public function totalWeight(): float
    {
        return (float) $this->components()->sum('weight_percent');
    }
}
