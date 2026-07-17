<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'logo', 'website', 'industry', 'phone', 'email',
    'address', 'address_line2', 'cin', 'city', 'country', 'default_state_code', 'timezone', 'date_format',
    'currency', 'currency_symbol', 'primary_color', 'secondary_color',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function jobTitles(): HasMany
    {
        return $this->hasMany(JobTitle::class);
    }

    /**
     * Get the headquarters office for this company.
     */
    public function headquarters(): ?Office
    {
        return $this->offices()->where('is_headquarters', true)->first();
    }

    /**
     * Get a formatted display name with city.
     */
    public function displayName(): string
    {
        return $this->city
            ? "{$this->name} ({$this->city})"
            : $this->name;
    }
}
