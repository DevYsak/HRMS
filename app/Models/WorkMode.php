<?php

namespace App\Models;

use Database\Factories\WorkModeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkMode extends Model
{
    /** @use HasFactory<WorkModeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'color',
        'requires_attendance_tracking', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requires_attendance_tracking' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
