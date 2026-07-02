<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['shift_start', 'shift_end', 'late_grace_period', 'requires_location', 'requires_qr', 'requires_photo'])]
class AttendanceSetting extends Model
{
    protected $casts = [
        'requires_location' => 'boolean',
        'requires_qr' => 'boolean',
        'requires_photo' => 'boolean',
    ];
}
