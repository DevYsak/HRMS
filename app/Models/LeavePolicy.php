<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How much leave an employee is entitled to, and on what terms.
 *
 * Deliberately holds weeks rather than days. "5.6 weeks" is the same rule for
 * everybody; "28 days" is only true for someone working five days a week, and
 * silently wrong for everybody else. Days are derived per employee from their
 * working pattern.
 */
#[Fillable([
    'name', 'description', 'statutory_weeks', 'contractual_additional_weeks',
    'bank_holiday_treatment', 'max_carry_over_days', 'carry_over_expiry_months',
    'irregular_accrual_rate', 'is_default', 'is_active',
])]
class LeavePolicy extends Model
{
    use SoftDeletes;

    public const BANK_HOLIDAYS_INCLUDED = 'included';

    public const BANK_HOLIDAYS_ADDITIONAL = 'additional';

    protected function casts(): array
    {
        return [
            'statutory_weeks' => 'decimal:2',
            'contractual_additional_weeks' => 'decimal:2',
            'max_carry_over_days' => 'decimal:2',
            'irregular_accrual_rate' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Whether bank holidays come out of the entitlement rather than on top. */
    public function bankHolidaysCountTowardEntitlement(): bool
    {
        return $this->bank_holiday_treatment === self::BANK_HOLIDAYS_INCLUDED;
    }

    public function totalWeeks(): float
    {
        return (float) $this->statutory_weeks + (float) $this->contractual_additional_weeks;
    }

    /** The policy to use when an employee has none of their own. */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('is_active', true)->first()
            ?? static::query()->where('is_active', true)->first();
    }
}
