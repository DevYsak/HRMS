<?php

namespace App\Services\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveYear;

/**
 * How a balance is built, bucket by bucket (Phase 2A).
 *
 *   Credits          = base + carry forward + accrual + add-on
 *                      + HR credit + opening (migrated, undecomposed)
 *   Approved available = credits − HR debit − expired − used − encashed
 *   Available to request = approved available − pending (reserved)
 *
 * Nothing here is floored: an overdrawn balance is reported as negative.
 * Pending requests reserve days for "available to request" only; they never
 * touch used_days until they are approved.
 */
class LeaveBalanceCalculator
{
    /** Request statuses that hold a reservation until decided. */
    public const RESERVING_STATUSES = ['pending', 'pending_hr', 'more_info_requested'];

    public function __construct(private readonly LeaveLedgerService $ledger) {}

    /**
     * @return array{
     *     base: float, carry_forward: float, accrued: float, add_on: float,
     *     adjustment_credit: float, adjustment_debit: float, opening: float,
     *     expired: float, used: float, encashed: float, credits: float,
     *     approved_available: float, pending: float, available_to_request: float,
     *     ledger_backed: bool, ledger_status: ?string
     * }
     */
    public function summary(LeaveBalance $balance, ?int $excludeRequestId = null): array
    {
        $f = fn (string $column) => round((float) ($balance->{$column} ?? 0), 2);

        if ($balance->isLedgerBacked()) {
            $credits = $f('base_days') + $f('carried_forward_days') + $f('accrued_days') + $f('add_on_days')
                + $f('adjustment_credit_days') + $f('opening_days');
            $parts = [
                'base' => $f('base_days'),
                'carry_forward' => $f('carried_forward_days'),
                'accrued' => $f('accrued_days'),
                'add_on' => $f('add_on_days'),
                'adjustment_credit' => $f('adjustment_credit_days'),
                'adjustment_debit' => $f('adjustment_debit_days'),
                'opening' => $f('opening_days'),
                'expired' => $f('expired_days'),
            ];
        } else {
            // Not yet migrated: only carried forward is known separately. The
            // rest of the allocation is reported as an undecomposed opening
            // figure rather than presented as a base entitlement.
            $credits = $f('allocated_days');
            $parts = [
                'base' => 0.0,
                'carry_forward' => $f('carried_forward_days'),
                'accrued' => 0.0,
                'add_on' => 0.0,
                'adjustment_credit' => 0.0,
                'adjustment_debit' => 0.0,
                'opening' => round($f('allocated_days') - $f('carried_forward_days'), 2),
                'expired' => 0.0,
            ];
        }

        $approved = round(
            $credits - $parts['adjustment_debit'] - $parts['expired'] - $f('used_days') - $f('encashed_days'),
            2,
        );
        $pending = $this->pendingDays($balance, $excludeRequestId);

        return $parts + [
            'used' => $f('used_days'),
            'encashed' => $f('encashed_days'),
            'credits' => round($credits, 2),
            'approved_available' => $approved,
            'pending' => $pending,
            'available_to_request' => round($approved - $pending, 2),
            'ledger_backed' => $balance->isLedgerBacked(),
            'ledger_status' => $balance->ledger_status,
        ];
    }

    /**
     * Paid days awaiting a decision whose leave falls in this balance's LEAVE
     * year (by start date) — not the calendar year.
     */
    public function pendingDays(LeaveBalance $balance, ?int $excludeRequestId = null): float
    {
        $year = $this->ledger->yearOf($balance);

        return $this->pendingFor($balance->employee_id, $balance->leave_type_id, $year, $excludeRequestId);
    }

    public function pendingFor(int $employeeId, int $leaveTypeId, LeaveYear $year, ?int $excludeRequestId = null): float
    {
        return round((float) LeaveRequest::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereIn('status', self::RESERVING_STATUSES)
            // Unpaid requests never draw on the balance.
            ->where(fn ($q) => $q->whereNull('requested_leave_status')->orWhere('requested_leave_status', 'paid'))
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())
            ->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->when($excludeRequestId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->sum('days'), 2);
    }
}
