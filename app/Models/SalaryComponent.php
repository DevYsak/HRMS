<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'type', 'is_fixed', 'default_amount', 'is_active',
    'code', 'component_type', 'calculation_type',
    'percentage_value', 'percentage_basis', 'percentage_of_component_id',
    'formula_expression', 'is_taxable', 'is_pf_applicable', 'is_esi_applicable',
    'display_order',
])]
class SalaryComponent extends Model
{
    protected function casts(): array
    {
        return [
            'is_fixed' => 'boolean',
            'is_active' => 'boolean',
            'is_taxable' => 'boolean',
            'is_pf_applicable' => 'boolean',
            'is_esi_applicable' => 'boolean',
            'display_order' => 'integer',
            'percentage_value' => 'decimal:2',
        ];
    }

    /** Returns component_type, falling back to the legacy type column. */
    public function getEffectiveComponentTypeAttribute(): string
    {
        return $this->component_type ?? $this->type;
    }

    public function percentageOfComponent(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'percentage_of_component_id');
    }

    public function dependentComponents(): HasMany
    {
        return $this->hasMany(SalaryComponent::class, 'percentage_of_component_id');
    }

    public function scopeEarnings(Builder $query): void
    {
        $query->where('type', 'earning');
    }

    public function scopeDeductions(Builder $query): void
    {
        $query->where('type', 'deduction');
    }

    public function scopeEmployerContributions(Builder $query): void
    {
        $query->where('component_type', 'employer_contribution');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('display_order')->orderBy('id');
    }
}
