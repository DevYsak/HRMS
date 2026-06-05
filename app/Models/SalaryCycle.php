<?php

namespace App\Models;

use Database\Factories\SalaryCycleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryCycle extends Model
{
    /** @use HasFactory<SalaryCycleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'start_day', 'end_day', 'pay_day',
        'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_day' => 'integer',
            'end_day' => 'integer',
            'pay_day' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    public function periodLabel(): string
    {
        $end = $this->end_day === 0 ? 'last' : (string) $this->end_day;

        return "{$this->start_day}th – {$end} of month";
    }
}
