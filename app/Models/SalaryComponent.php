<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'type', 'is_fixed', 'default_amount', 'is_active'])]
class SalaryComponent extends Model
{
    protected $casts = [
        'is_fixed' => 'boolean',
        'is_active' => 'boolean',
    ];
}
