<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single place where a leave event changes a balance (Phase 2A).
 *
 * A ledger-backed balance (or a legacy row that classifies SAFE and is
 * migrated on first touch) moves only through ledger postings. A legacy row
 * whose history is ambiguous keeps the old column arithmetic until HR runs
 * the backfill on purpose — it is never silently re-interpreted here.
 *
 * Every balance is resolved from the LEAVE year of the date the movement is
 * about (the leave's start date), never from today's calendar year.
 */
class LeaveMovementService
{
    public function __construct(
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveLedgerBackfillService $backfill,
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveYearResolver $years,
    ) {}

    /** The balance row for the leave year containing $date, created empty if missing. */
    public function balanceFor(int $employeeId, int $leaveTypeId, CarbonInterface $date): LeaveBalance
    {
        $year = $this->years->forDate($date);

        return LeaveBalance::firstOrCreate(
            ['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'year' => $year->legacyYear()],
            [
                'leave_year_id' => $year->id,
                'allocated_days' => 0, 'used_days' => 0, 'carried_forward_days' => 0,
                'encashed_days' => 0, 'comp_off_credits' => 0,
            ],
        );
    }

    /** Ledger-backed already, or SAFE and migrated now. */
    public function ledgerReady(LeaveBalance $balance): bool
    {
        return $balance->isLedgerBacked() || $this->backfill->migrateIfSafe($balance);
    }

    /** Approved available balance, unfloored. */
    public function approvedAvailable(LeaveBalance $balance): float
    {
        return $this->calculator->summary($balance)['approved_available'];
    }

    // ── Usage ───────────────────────────────────────────────────────────────

    /**
     * Turn an approved paid request into usage in the leave year it falls in.
     *
     * @throws DomainException when the balance cannot cover it and $enforceBalance
     */
    public function recordUsage(LeaveRequest $request, float $days, int $leaveTypeId, CarbonInterface $startDate, bool $enforceBalance = true, ?User $actor = null): LeaveBalance
    {
        $balance = $this->balanceFor($request->employee_id, $leaveTypeId, $startDate);

        if ($enforceBalance) {
            $available = $this->approvedAvailable($balance);
            if ($available + 0.001 < $days) {
                throw new DomainException("Insufficient leave balance. Available: {$available} day(s), requested: {$days}.");
            }
        }

        if (! $this->ledgerReady($balance)) {
            $balance->increment('used_days', $days);

            return $balance->fresh();
        }

        // Never two live usage entries for one request: re-approval after a
        // reversal posts a new version, a retry of the same approval does not.
        if ($this->ledger->activeEntryFor('leave_request', $request->id, LeaveLedgerEntry::TYPE_USAGE)) {
            return $balance;
        }

        $version = $this->ledger->nextVersion('leave_request', $request->id, LeaveLedgerEntry::TYPE_USAGE);
        $this->ledger->debit($balance, LeaveLedgerEntry::TYPE_USAGE, $days, $startDate, "usage:leave_request:{$request->id}:v{$version}", [
            'source_type' => 'leave_request',
            'source_id' => $request->id,
            'reason' => 'Leave approved',
            'actor' => $actor,
        ]);

        return $this->ledger->rebuild($balance);
    }

    /**
     * Give a request's days back, in the leave year the leave belonged to.
     * The legacy fallback uses the type, days and start date the request was
     * approved with (the caller passes them, as a re-review may change them).
     */
    public function reverseUsage(LeaveRequest $request, int $leaveTypeId, float $days, CarbonInterface $startDate, string $reason, ?User $actor = null): void
    {
        if ($entry = $this->ledger->activeEntryFor('leave_request', $request->id, LeaveLedgerEntry::TYPE_USAGE)) {
            $this->ledger->reverse($entry, $reason, $actor);
            $this->ledger->rebuild($this->balanceFor($request->employee_id, $entry->leave_type_id, Carbon::parse($entry->effective_date)));

            return;
        }

        $balance = LeaveBalance::where('employee_id', $request->employee_id)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $this->years->legacyYearFor($startDate))
            ->first();

        if ($balance === null) {
            return;
        }

        if ($balance->isLedgerBacked()) {
            // Approved before the row was migrated, so its usage is inside the
            // migrated opening figure: return it as a usage credit.
            $this->ledger->credit($balance, LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT, $days, Carbon::today(),
                "usage_return:leave_request:{$request->id}:".$this->ledger->nextVersion('leave_request_return', $request->id, LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT), [
                    'source_type' => 'leave_request_return', 'source_id' => $request->id,
                    'reason' => $reason.' (usage approved before ledger migration)', 'actor' => $actor,
                ]);
            $this->ledger->rebuild($balance);

            return;
        }

        $balance->decrement('used_days', $days);
    }

    /** Usage from an approved leave regularisation. */
    public function recordRegularisationUsage(int $employeeId, int $leaveTypeId, int $regularisationId, float $days, CarbonInterface $date, ?User $actor = null): LeaveBalance
    {
        $balance = $this->balanceFor($employeeId, $leaveTypeId, $date);

        if (! $this->ledgerReady($balance)) {
            $balance->used_days = round((float) $balance->used_days + $days, 2);
            $balance->save();

            return $balance->fresh();
        }

        $this->ledger->debit($balance, LeaveLedgerEntry::TYPE_USAGE, $days, $date, "usage:leave_regularisation:{$regularisationId}:v1", [
            'source_type' => 'leave_regularisation', 'source_id' => $regularisationId,
            'reason' => 'Leave regularisation approved', 'actor' => $actor,
        ]);

        return $this->ledger->rebuild($balance);
    }

    // ── Credits ─────────────────────────────────────────────────────────────

    /** Comp-off earned on $date, as an add-on lot in that date's leave year. */
    public function creditCompOff(Employee $employee, LeaveType $type, CarbonInterface $date, float $days): LeaveBalance
    {
        $balance = $this->balanceFor($employee->id, $type->id, $date);

        if (! $this->ledgerReady($balance)) {
            $balance->incrementEach(['allocated_days' => $days, 'comp_off_credits' => $days]);

            return $balance->fresh();
        }

        $this->ledger->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, $days, $date, "comp_off:{$employee->id}:{$type->id}:".$date->toDateString(), [
            'source_type' => 'comp_off', 'source_id' => $employee->id,
            'reason' => 'Comp-off earned for working on '.$date->toDateString(),
            'meta' => ['add_on_type' => 'comp_off_credit', 'worked_on' => $date->toDateString()],
        ]);

        return $this->ledger->rebuild($balance);
    }

    /**
     * One month's accrual. Idempotent through the accrual log key; never
     * touches any other figure of the balance.
     */
    public function creditAccrual(LeaveBalance $balance, int $accrualLogId, float $days, CarbonInterface $monthStart): LeaveBalance
    {
        if (! $this->ledgerReady($balance)) {
            // Legacy row: add the month, nothing else. (The old code reset
            // allocated, used and carried-forward to zero first.)
            $balance->increment('allocated_days', $days);

            return $balance->fresh();
        }

        $this->ledger->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, $days, $monthStart, "accrual:log:{$accrualLogId}", [
            'source_type' => 'leave_accrual_log', 'source_id' => $accrualLogId,
            'reason' => 'Monthly accrual for '.$monthStart->format('F Y'),
        ]);

        return $this->ledger->rebuild($balance);
    }

    /**
     * Set the carried-forward credit of a target year to $carried. Called by
     * the carry-forward services, which decide the amount; this only makes
     * the balance say it. Non-incremental: setting the same figure twice is a
     * no-op, lowering it reverses the live carry-forward lots first.
     */
    public function setCarriedForward(LeaveBalance $balance, float $carried, int $transactionId, ?User $actor = null): LeaveBalance
    {
        $carried = round(max(0, $carried), 2);

        if (! $this->ledgerReady($balance)) {
            $fresh = (float) ($balance->allocated_days ?? 0) - (float) ($balance->carried_forward_days ?? 0);
            $balance->allocated_days = round($fresh + $carried, 2);
            $balance->carried_forward_days = $carried;
            $balance->used_days = (float) ($balance->used_days ?? 0);
            $balance->encashed_days = (float) ($balance->encashed_days ?? 0);
            $balance->save();

            return $balance->fresh();
        }

        $year = $this->ledger->yearOf($balance);
        $live = $this->liveCarryForward($balance, $year);
        $current = round((float) $live->sum('days'), 2);

        if (abs($current - $carried) < 0.001) {
            return $balance;
        }

        if ($carried < $current) {
            foreach ($live as $entry) {
                $this->ledger->reverse($entry, 'Carry forward revised', $actor);
            }
            $current = 0.0;
        }

        $delta = round($carried - $current, 2);

        if ($delta > 0) {
            $sequence = LeaveLedgerEntry::where('source_type', 'leave_carry_forward_transaction')
                ->where('source_id', $transactionId)->whereNull('reverses_entry_id')->count() + 1;

            $this->ledger->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, $delta, $year->starts_on->copy(),
                "carry_forward:tx:{$transactionId}:{$sequence}", [
                    'source_type' => 'leave_carry_forward_transaction', 'source_id' => $transactionId,
                    'expires_on' => $this->carryForwardExpiry($balance, $year),
                    'reason' => 'Carried forward from the previous leave year',
                    'actor' => $actor,
                ]);
        }

        return $this->ledger->rebuild($balance);
    }

    /** @return Collection<int, LeaveLedgerEntry> */
    private function liveCarryForward(LeaveBalance $balance, LeaveYear $year)
    {
        return LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->where('entry_type', LeaveLedgerEntry::TYPE_CARRY_FORWARD)
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversedBy')
            ->get();
    }

    /** Expiry from the employee's policy rule (fixed date or months), falling back to the policy. */
    private function carryForwardExpiry(LeaveBalance $balance, LeaveYear $year): ?string
    {
        $employee = $balance->employee;
        $type = $balance->leaveType;

        if ($employee === null || $type === null) {
            return null;
        }

        $rules = app(LeaveRuleResolver::class);

        return $rules->carryForwardExpiry($rules->settings($employee, $type), $year);
    }
}
