<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['shift_start', 'shift_end', 'late_grace_period', 'requires_location', 'requires_qr'])]
class AttendanceSetting extends Model
{
    protected $casts = [
        'requires_location' => 'boolean',
        'requires_qr' => 'boolean',
    ];
}
