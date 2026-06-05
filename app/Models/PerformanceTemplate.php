<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'code', 'description',
    'applies_to_type', 'applies_to_id',
    'cycle_type', 'is_active', 'created_by',
])]
class PerformanceTemplate extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(PerformanceCategory::class, 'template_id')->orderBy('sort_order');
    }

    public function components(): HasMany
    {
        return $this->hasMany(PerformanceComponent::class, 'template_id')->orderBy('sort_order');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(PerformanceCycle::class, 'template_id');
    }

    public function autoScoreConfigs(): HasMany
    {
        return $this->hasMany(PerformanceAutoScoreConfig::class, 'template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalWeight(): float
    {
        return (float) $this->components()->sum('weight_percent');
    }

    public function isWeightageValid(): bool
    {
        return abs($this->totalWeight() - 100.0) < 0.01;
    }

    public function appliesTo(): string
    {
        return match ($this->applies_to_type) {
            'role' => 'Role',
            'department' => 'Department',
            'designation' => 'Designation',
            'employment_type' => 'Employment Type',
            'shift' => 'Shift',
            'branch' => 'Branch',
            default => 'Global (All Employees)',
        };
    }

    public function cycleTypeLabel(): string
    {
        return match ($this->cycle_type) {
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'half_yearly' => 'Half Yearly',
            'annual' => 'Annual',
            'custom' => 'Custom',
            default => ucfirst($this->cycle_type),
        };
    }
}
