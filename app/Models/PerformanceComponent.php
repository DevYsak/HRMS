<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'category_id', 'template_id', 'name', 'description',
    'scoring_type', 'max_score', 'weight_percent', 'target_value', 'sort_order',
])]
class PerformanceComponent extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'max_score' => 'float',
            'weight_percent' => 'float',
            'target_value' => 'float',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PerformanceCategory::class, 'category_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PerformanceTemplate::class, 'template_id');
    }

    public function reviewScores(): HasMany
    {
        return $this->hasMany(PerformanceReviewScore::class, 'component_id');
    }

    public function employeeKpis(): HasMany
    {
        return $this->hasMany(EmployeeKpi::class, 'component_id');
    }

    public function autoScoreConfig(): HasOne
    {
        return $this->hasOne(PerformanceAutoScoreConfig::class, 'component_id');
    }

    public function isAutoScored(): bool
    {
        return $this->scoring_type !== 'manual';
    }

    public function scoringTypeLabel(): string
    {
        return match ($this->scoring_type) {
            'manual' => 'Manual',
            'auto_attendance' => 'Auto (Attendance)',
            'auto_punctuality' => 'Auto (Punctuality)',
            'auto_leave' => 'Auto (Leave)',
            'auto_productivity' => 'Auto (Productivity)',
            'auto_shift_compliance' => 'Auto (Shift Compliance)',
            default => ucfirst($this->scoring_type),
        };
    }
}
