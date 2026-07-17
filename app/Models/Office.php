<?php

namespace App\Models;

use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'name', 'address', 'city', 'state_code', 'country',
    'timezone', 'latitude', 'longitude', 'radius', 'is_headquarters',
])]
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope: only headquarters offices.
     */
    public function scopeHeadquarters(Builder $query): void
    {
        $query->where('is_headquarters', true);
    }

    /**
     * Get a formatted location string.
     */
    public function location(): string
    {
        return implode(', ', array_filter([$this->city, $this->country]));
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
