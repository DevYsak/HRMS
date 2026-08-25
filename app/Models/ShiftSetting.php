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
        'code',
        'is_default',
        'start_time',
        'end_time',
        'break_duration',
        'grace_minutes',
        'standard_hours',
        'ot_threshold_hours',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'standard_hours' => 'float',
            'ot_threshold_hours' => 'float',
        ];
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'shift_id');
    }

    /**
     * Only one shift can be the company default.
     *
     * Enforced here rather than by a partial unique index, which MySQL does not
     * support: two defaults would make the fallback arbitrary again, which is
     * the exact failure this whole mechanism replaced.
     */
    public function makeCompanyDefault(): void
    {
        static::query()->where('is_default', true)->whereKeyNot($this->id)->update(['is_default' => false]);

        $this->forceFill(['is_default' => true])->save();
    }
}
