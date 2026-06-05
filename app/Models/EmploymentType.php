<?php

namespace App\Models;

use Database\Factories\EmploymentTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmploymentType extends Model
{
    /** @use HasFactory<EmploymentTypeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'probation_days', 'notice_period_days',
        'leave_eligible', 'payroll_eligible', 'ot_eligible',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'probation_days' => 'integer',
            'notice_period_days' => 'integer',
            'leave_eligible' => 'boolean',
            'payroll_eligible' => 'boolean',
            'ot_eligible' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function probationSetting(): HasOne
    {
        return $this->hasOne(ProbationSetting::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
