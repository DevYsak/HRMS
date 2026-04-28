<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShiftSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'break_duration',
        'grace_minutes',
        'standard_hours',
        'ot_threshold_hours',
        'description',
    ];

    public function employees()
    {
        return $this->hasMany(Employee::class, 'shift_id');
    }
}
