<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One leave balance movement. Append-only: corrections are new entries that
 * reverse an earlier one (reverses_entry_id), never edits.
 *
 * Credits are positive, debits negative. A reversal carries the same
 * entry_type and bucket as the entry it reverses, with the opposite sign, so
 * every bucket total is simply the sum of its rows.
 */
#[Fillable([
    'employee_id', 'leave_type_id', 'leave_year_id', 'entry_type', 'bucket', 'days',
    'effective_date', 'expires_on', 'source_type', 'source_id', 'reverses_entry_id',
    'idempotency_key', 'reason', 'meta', 'created_by',
])]
class LeaveLedgerEntry extends Model
{
    public $timestamps = false;

    // ── Entry types ─────────────────────────────────────────────────────────

    public const TYPE_BASE = 'base_entitlement';

    public const TYPE_CARRY_FORWARD = 'carry_forward';

    public const TYPE_ACCRUAL = 'accrual';

    public const TYPE_ADD_ON = 'add_on';

    public const TYPE_ADJUSTMENT_CREDIT = 'adjustment_credit';

    public const TYPE_ADJUSTMENT_DEBIT = 'adjustment_debit';

    /** A migrated balance whose composition could not be decomposed honestly. */
    public const TYPE_OPENING = 'opening_balance';

    public const TYPE_USAGE = 'usage';

    public const TYPE_ENCASHMENT = 'encashment';

    public const TYPE_EXPIRY = 'expiry';

    // ── Buckets ─────────────────────────────────────────────────────────────

    public const BUCKET_BASE = 'base';

    public const BUCKET_CARRY_FORWARD = 'carry_forward';

    public const BUCKET_ACCRUAL = 'accrual';

    public const BUCKET_ADD_ON = 'add_on';

    public const BUCKET_ADJUSTMENT = 'adjustment';

    public const BUCKET_OPENING = 'opening';

    public const BUCKET_USAGE = 'usage';

    public const BUCKET_ENCASHMENT = 'encashment';

    public const BUCKET_EXPIRY = 'expiry';

    /** @var array<string, string> entry type => bucket */
    public const BUCKET_FOR = [
        self::TYPE_BASE => self::BUCKET_BASE,
        self::TYPE_CARRY_FORWARD => self::BUCKET_CARRY_FORWARD,
        self::TYPE_ACCRUAL => self::BUCKET_ACCRUAL,
        self::TYPE_ADD_ON => self::BUCKET_ADD_ON,
        self::TYPE_ADJUSTMENT_CREDIT => self::BUCKET_ADJUSTMENT,
        self::TYPE_ADJUSTMENT_DEBIT => self::BUCKET_ADJUSTMENT,
        self::TYPE_OPENING => self::BUCKET_OPENING,
        self::TYPE_USAGE => self::BUCKET_USAGE,
        self::TYPE_ENCASHMENT => self::BUCKET_ENCASHMENT,
        self::TYPE_EXPIRY => self::BUCKET_EXPIRY,
    ];

    /** Entry types that add days (the credit lots debits consume). */
    public const CREDIT_TYPES = [
        self::TYPE_BASE, self::TYPE_CARRY_FORWARD, self::TYPE_ACCRUAL,
        self::TYPE_ADD_ON, self::TYPE_ADJUSTMENT_CREDIT, self::TYPE_OPENING,
    ];

    /**
     * Default consumption order (lower first): expiring carry forward, then
     * add-on, accrual, base entitlement, positive HR adjustments, and finally
     * migrated opening balances. Within a bucket the earliest expiry goes first.
     *
     * @var array<string, int>
     */
    public const CONSUMPTION_RANK = [
        self::TYPE_CARRY_FORWARD => 1,
        self::TYPE_ADD_ON => 2,
        self::TYPE_ACCRUAL => 3,
        self::TYPE_BASE => 4,
        self::TYPE_ADJUSTMENT_CREDIT => 5,
        self::TYPE_OPENING => 6,
    ];

    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
            'effective_date' => 'date',
            'expires_on' => 'date',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Leave ledger entries are immutable; post a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Leave ledger entries cannot be deleted; post a reversal instead.'));
    }

    public function isCredit(): bool
    {
        return in_array($this->entry_type, self::CREDIT_TYPES, true) && $this->reverses_entry_id === null;
    }

    public function isReversal(): bool
    {
        return $this->reverses_entry_id !== null;
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

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    /** Consumption rows against this entry when it is a credit lot. */
    public function consumptionsOfThisCredit(): HasMany
    {
        return $this->hasMany(LeaveCreditConsumption::class, 'credit_entry_id');
    }

    /** Consumption rows this entry made when it is a debit (or a reversal of one). */
    public function consumptionsMade(): HasMany
    {
        return $this->hasMany(LeaveCreditConsumption::class, 'debit_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
