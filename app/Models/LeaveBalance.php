<?php

namespace App\Models;

use App\Services\Leave\LeaveBalanceCalculator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'employee_id',
    'leave_type_id',
    'allocated_days',
    'used_days',
    'carried_forward_days',
    'encashed_days',
    'comp_off_credits',
    'year',
    // The authoritative link to the leave year. Absent from fillable, every
    // mass assignment left it null and the row was only ever found by the
    // legacy-integer fallback.
    'leave_year_id',
    // Whether the figure beside it is a measurement or a placeholder. A
    // closed year we never had usage data for must not claim zero.
    'used_days_unknown',
    'encashed_days_unknown',
])]
class LeaveBalance extends Model
{
    public const LEDGER_SAFE = 'safe';

    public const LEDGER_NEEDS_HR_REVIEW = 'needs_hr_review';

    /** Figures that, once a row is ledger-backed, only a ledger rebuild may write. */
    public const LEDGER_OWNED = [
        'allocated_days', 'used_days', 'carried_forward_days', 'encashed_days', 'comp_off_credits',
        'base_days', 'accrued_days', 'add_on_days', 'adjustment_credit_days', 'adjustment_debit_days',
        'opening_days', 'expired_days',
    ];

    /** Set only while LeaveLedgerService rebuilds a row from the ledger. */
    public static bool $rebuildingFromLedger = false;

    protected function casts(): array
    {
        return [
            'allocated_days' => 'decimal:2',
            'base_days' => 'decimal:2',
            'used_days' => 'decimal:2',
            'carried_forward_days' => 'decimal:2',
            'accrued_days' => 'decimal:2',
            'add_on_days' => 'decimal:2',
            'adjustment_credit_days' => 'decimal:2',
            'adjustment_debit_days' => 'decimal:2',
            'opening_days' => 'decimal:2',
            'expired_days' => 'decimal:2',
            'encashed_days' => 'decimal:2',
            'comp_off_credits' => 'decimal:2',
            'year' => 'integer',
            // Without these the flags come back as 1/0, so a strict check for
            // "is this figure unknown" silently fails and the absence reads
            // as a recorded value.
            'used_days_unknown' => 'boolean',
            'encashed_days_unknown' => 'boolean',
            'ledger_migrated_at' => 'datetime',
        ];
    }

    /**
     * A ledger-backed row is a summary of the ledger. Writing its figures
     * directly would make it disagree with the record it summarises, so any
     * code path that still does so fails loudly instead of drifting silently.
     */
    protected static function booted(): void
    {
        static::updating(function (LeaveBalance $balance) {
            if (static::$rebuildingFromLedger || $balance->getOriginal('ledger_migrated_at') === null) {
                return;
            }

            if ($balance->isDirty(self::LEDGER_OWNED)) {
                throw new LogicException(
                    'This leave balance is ledger-backed: post a ledger entry through LeaveLedgerService instead of editing its figures.'
                );
            }
        });
    }

    public function isLedgerBacked(): bool
    {
        return $this->ledger_migrated_at !== null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function leaveYear(): BelongsTo
    {
        return $this->belongsTo(LeaveYear::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(LeaveBalanceAdjustment::class, 'leave_type_id', 'leave_type_id')
            ->where('employee_id', $this->employee_id);
    }

    /**
     * Available balance = allocated - used - encashed, floored at zero.
     *
     * Kept for the legacy screens that display it. The real (possibly
     * negative) figure, and the pending reservation, come from
     * LeaveBalanceCalculator — nothing that decides anything should read
     * this floored value.
     */
    public function available(): float
    {
        return max(0, $this->realAvailable());
    }

    /** Approved available balance, unfloored: a real overdraw stays visible. */
    public function realAvailable(): float
    {
        return round((float) $this->allocated_days - (float) $this->used_days - (float) ($this->encashed_days ?? 0), 2);
    }

    /** Paid days awaiting a decision, in this row's LEAVE year (not the calendar year). */
    public function pendingDays(): float
    {
        return app(LeaveBalanceCalculator::class)->pendingDays($this);
    }
}
