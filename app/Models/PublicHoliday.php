<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['date', 'name', 'country'])]
class PublicHoliday extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** Check if a given date is a public holiday for a country. */
    public static function isHoliday(\Illuminate\Support\Carbon $date, string $country = 'IN'): bool
    {
        return static::where('country', $country)
            ->where('date', $date->toDateString())
            ->exists();
    }
}
