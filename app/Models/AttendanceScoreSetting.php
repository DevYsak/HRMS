<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-configurable weights for the Attendance Score Engine (Rule 11).
 * Singleton row; every factor's points are DB-driven so scoring policy is
 * editable without a code change.
 */
#[Fillable([
    'late_arrival_penalty', 'late_per_30m_penalty', 'early_exit_penalty',
    'missing_punch_penalty', 'auto_punch_out_penalty', 'regularization_penalty',
    'break_violation_penalty', 'short_hours_penalty',
    'overtime_bonus', 'holiday_work_bonus',
])]
class AttendanceScoreSetting extends Model
{
    protected $casts = [
        'late_arrival_penalty' => 'float',
        'late_per_30m_penalty' => 'float',
        'early_exit_penalty' => 'float',
        'missing_punch_penalty' => 'float',
        'auto_punch_out_penalty' => 'float',
        'regularization_penalty' => 'float',
        'break_violation_penalty' => 'float',
        'short_hours_penalty' => 'float',
        'overtime_bonus' => 'float',
        'holiday_work_bonus' => 'float',
    ];

    /** The active settings row, created with schema defaults on first use. */
    public static function current(): self
    {
        // fresh() after create: the DB fills the column defaults, which the
        // in-memory model doesn't have until re-read.
        return static::query()->first() ?? static::query()->create([])->fresh();
    }
}
