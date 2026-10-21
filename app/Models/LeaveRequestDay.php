<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One date of a Combined Leave request (Leave V2): how that date is allocated
 * — full or half, paid or unpaid — or why it is excluded (weekly off,
 * holiday). The request header holds the totals; these rows are what payroll
 * and attendance read per date. The balance is debited once, from the header.
 */
#[Fillable([
    'leave_request_id', 'date', 'day_status', 'allocation_type', 'paid_units', 'unpaid_units', 'session',
    'reason_leave_type_id', 'is_excluded', 'exclusion_reason', 'balance_leave_type_id', 'metadata',
])]
class LeaveRequestDay extends Model
{
    public const FULL_PAID = 'full_paid';

    public const FULL_UNPAID = 'full_unpaid';

    public const HALF_PAID = 'half_paid';

    public const HALF_UNPAID = 'half_unpaid';

    public const EXCLUDED = 'excluded';

    public const ALLOCATIONS = [self::FULL_PAID, self::FULL_UNPAID, self::HALF_PAID, self::HALF_UNPAID];

    public const LABELS = [
        self::FULL_PAID => 'Full Day – Paid',
        self::FULL_UNPAID => 'Full Day – Unpaid',
        self::HALF_PAID => 'Half Day – Paid',
        self::HALF_UNPAID => 'Half Day – Unpaid',
        self::EXCLUDED => 'Excluded',
    ];

    /** Paid and unpaid units of an allocation: a half day is always 0.5, never a full day. */
    public const UNITS = [
        self::FULL_PAID => [1.0, 0.0],
        self::FULL_UNPAID => [0.0, 1.0],
        self::HALF_PAID => [0.5, 0.0],
        self::HALF_UNPAID => [0.0, 0.5],
        self::EXCLUDED => [0.0, 0.0],
    ];

    public const STATUS_WORKING = 'working';

    public const STATUS_WEEKLY_OFF = 'weekly_off';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_SHUTDOWN = 'shutdown';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'paid_units' => 'decimal:2',
            'unpaid_units' => 'decimal:2',
            'is_excluded' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public static function isHalf(string $allocation): bool
    {
        return in_array($allocation, [self::HALF_PAID, self::HALF_UNPAID], true);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function reasonLeaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'reason_leave_type_id')->withTrashed();
    }
}
