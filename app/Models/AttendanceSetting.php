<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['shift_start', 'shift_end', 'late_grace_period', 'auto_checkout_buffer_minutes', 'ot_auto_close_time', 'ot_rate_per_hour', 'requires_location', 'requires_qr', 'requires_photo'])]
class AttendanceSetting extends Model
{
    protected $casts = [
        'ot_rate_per_hour' => 'float',
        'auto_checkout_buffer_minutes' => 'integer',
        'requires_location' => 'boolean',
        'requires_qr' => 'boolean',
        'requires_photo' => 'boolean',
    ];
}
