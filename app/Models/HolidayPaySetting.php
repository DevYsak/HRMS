<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings row governing Holiday Work pay: which pay types are
 * offered, the double-pay multiplier, comp-off/extra-leave day counts, an
 * optional OT rate override, and free-text company policy notes.
 */
#[Fillable([
    'allowed_pay_types', 'default_pay_type', 'double_pay_multiplier',
    'comp_off_days_per_holiday', 'extra_leave_days_per_holiday', 'half_day_comp_off_days',
    'ot_rate_per_hour', 'auto_approve_after_manager', 'policy_notes',
])]
class HolidayPaySetting extends Model
{
    public const ALL_PAY_TYPES = ['overtime', 'comp_off', 'double_pay', 'extra_leave', 'half_day'];

    protected function casts(): array
    {
        return [
            'allowed_pay_types' => 'array',
            'double_pay_multiplier' => 'decimal:2',
            'comp_off_days_per_holiday' => 'decimal:2',
            'extra_leave_days_per_holiday' => 'decimal:2',
            'half_day_comp_off_days' => 'decimal:2',
            'ot_rate_per_hour' => 'decimal:2',
            'auto_approve_after_manager' => 'boolean',
        ];
    }

    /** The single settings row, creating sensible defaults on first use. */
    public static function current(): self
    {
        return static::query()->first() ?? static::create([
            'allowed_pay_types' => self::ALL_PAY_TYPES,
            'default_pay_type' => 'overtime',
        ]);
    }

    /** @return array<int, string> */
    public function enabledPayTypes(): array
    {
        $enabled = $this->allowed_pay_types;

        return is_array($enabled) && $enabled !== [] ? $enabled : self::ALL_PAY_TYPES;
    }

    public function isPayTypeAllowed(string $type): bool
    {
        return in_array($type, $this->enabledPayTypes(), true);
    }

    public static function payTypeLabels(): array
    {
        return [
            'overtime' => 'Overtime',
            'comp_off' => 'Comp Off',
            'double_pay' => 'Double Pay',
            'extra_leave' => 'Extra Leave',
            'half_day' => 'Half Day',
        ];
    }
}
