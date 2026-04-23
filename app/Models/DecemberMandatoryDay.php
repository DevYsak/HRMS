<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['year', 'date', 'description'])]
class DecemberMandatoryDay extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** Check if a given date is a December Mandatory Leave day. */
    public static function isMandatory(\Illuminate\Support\Carbon $date): bool
    {
        return static::where('year', $date->year)
            ->where('date', $date->toDateString())
            ->exists();
    }
}
