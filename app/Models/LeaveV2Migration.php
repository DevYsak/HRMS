<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One employee's Leave V2 migration for a leave year: the Earned Leave they
 * start with, the source balance it was taken from (or HR's override), the
 * opening ledger entry and who ran it. Immutable — the record is the audit.
 */
#[Fillable([
    'employee_id', 'leave_year_id', 'earned_leave_type_id', 'earned_balance_id',
    'source_leave_type_id', 'source_balance_id', 'source_available', 'migrated_available',
    'policy_entitlement', 'override_reason', 'ledger_entry_id', 'effective_date',
    'migration_key', 'meta', 'created_by',
])]
class LeaveV2Migration extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'source_available' => 'decimal:2',
            'migrated_available' => 'decimal:2',
            'policy_entitlement' => 'decimal:2',
            'effective_date' => 'date',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A Leave V2 migration record is immutable.'));
        static::deleting(fn () => throw new LogicException('A Leave V2 migration record cannot be deleted.'));
    }

    public static function keyFor(int $employeeId, int $earnedTypeId, int $leaveYearId): string
    {
        return "leave-v2-migration:{$employeeId}:{$earnedTypeId}:{$leaveYearId}";
    }

    /** Has this employee's Earned Leave for that year already been set by the migration? */
    public static function done(int $employeeId, int $earnedTypeId, int $leaveYearId): bool
    {
        return static::where('employee_id', $employeeId)->where('earned_leave_type_id', $earnedTypeId)
            ->where('leave_year_id', $leaveYearId)->exists();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveYear(): BelongsTo
    {
        return $this->belongsTo(LeaveYear::class);
    }

    public function earnedLeaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'earned_leave_type_id')->withTrashed();
    }

    public function sourceLeaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'source_leave_type_id')->withTrashed();
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LeaveLedgerEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
