<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveAllocationPolicy;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveYearResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaveBalanceService
{
    /**
     * Initialize leave balances for a new employee from the central leave type master.
     * Safe to call multiple times — uses updateOrCreate so existing data is not overwritten.
     */
    public function initializeForEmployee(Employee $employee, int $year): void
    {
        $allocatableTypes = LeaveType::whereNotNull('annual_allocation_days')
            ->where('annual_allocation_days', '>', 0)
            ->whereNull('deleted_at')
            ->get();

        foreach ($allocatableTypes as $type) {
            // firstOrCreate: if the record exists, leave all existing balances untouched.
            // If it is new, seed it with the master allocation.
            LeaveBalance::firstOrCreate(
                [
                    'employee_id' => $employee->id,
                    'leave_type_id' => $type->id,
                    'year' => $year,
                ],
                [
                    'allocated_days' => $type->annual_allocation_days,
                    'used_days' => 0,
                    'carried_forward_days' => 0,
                    'encashed_days' => 0,
                    'comp_off_credits' => 0,
                ],
            );
        }
    }

    /**
     * Initialize leave balances using the conditional allocation policies, falling
     * back to each leave type's uniform annual_allocation_days when no policy
     * matches. Safe to call repeatedly (firstOrCreate).
     *
     * Annual leave is excluded by its caller: it is calculated from the policy
     * and the working pattern, so a flat number on the type must not reach it.
     *
     * @param  array<int, int>  $excludeTypeIds
     */
    public function initializeFromPolicy(Employee $employee, int $year, array $excludeTypeIds = []): void
    {
        $types = LeaveType::whereNull('deleted_at')
            ->when($excludeTypeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeTypeIds))
            ->get();
        $policiesByType = LeaveAllocationPolicy::where('is_active', true)->get()->groupBy('leave_type_id');

        foreach ($types as $type) {
            $days = $this->resolveAllocation($employee, $type, $policiesByType->get($type->id) ?? collect());

            if ($days === null || $days <= 0) {
                continue;
            }

            LeaveBalance::firstOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                [
                    // Linked to the leave year, not only its legacy integer.
                    'leave_year_id' => app(LeaveYearResolver::class)->forDate(Carbon::create($year, 7, 1))->id,
                    'allocated_days' => $days,
                    'used_days' => 0,
                    'carried_forward_days' => 0,
                    'encashed_days' => 0,
                    'comp_off_credits' => 0,
                ],
            );
        }
    }

    /**
     * Resolve the default allocation for one leave type for a given employee:
     * the most specific matching policy wins; otherwise the type's uniform
     * annual_allocation_days (or null if that too is unset).
     *
     * @param  Collection<int, LeaveAllocationPolicy>  $policies
     */
    public function resolveAllocation(Employee $employee, LeaveType $type, Collection $policies): ?float
    {
        $matching = $policies->filter(fn (LeaveAllocationPolicy $p) => $this->policyMatches($p, $employee));

        if ($matching->isNotEmpty()) {
            $best = $matching->sortByDesc(fn (LeaveAllocationPolicy $p) => $p->specificity())->first();

            return (float) $best->allocated_days;
        }

        return $type->annual_allocation_days !== null ? (float) $type->annual_allocation_days : null;
    }

    /** All non-null conditions on the policy must match the employee. */
    private function policyMatches(LeaveAllocationPolicy $policy, Employee $employee): bool
    {
        if ($policy->department_id && $policy->department_id !== $employee->department_id) {
            return false;
        }
        if ($policy->job_title_id && $policy->job_title_id !== $employee->job_title_id) {
            return false;
        }
        if ($policy->employment_type_id && $policy->employment_type_id !== $employee->employment_type_id) {
            return false;
        }
        if ($policy->gender && strtolower($policy->gender) !== strtolower((string) $employee->gender)) {
            return false;
        }
        if ($policy->role && $policy->role !== ($employee->user?->role?->value)) {
            return false;
        }
        if ($policy->min_service_months > 0) {
            $months = $employee->joining_date
                ? Carbon::parse($employee->joining_date)->diffInMonths(now())
                : 0;
            if ($months < $policy->min_service_months) {
                return false;
            }
        }
        if ($policy->requires_probation_complete) {
            $status = $employee->status instanceof EmployeeStatus
                ? $employee->status->value
                : $employee->status;
            if (\in_array($status, ['draft', 'onboarding', 'probation'], true)) {
                return false;
            }
        }

        return true;
    }

    /** Kinds of HR movement an adjustment can be. */
    public const CATEGORY_ADJUSTMENT = 'adjustment';

    public const CATEGORY_ADD_ON = 'add_on';

    public const CATEGORY_CORRECTION = 'correction';

    /** @var array<int, string> */
    public const ADD_ON_TYPES = [
        'manual_adjustment', 'special_leave', 'management_grant', 'comp_off_credit',
        'opening_balance_correction', 'historical_balance', 'other',
    ];

    /**
     * Manually credit or debit an employee's leave balance.
     *
     * On a ledger-backed balance the movement is posted as its own bucket —
     * an add-on lot (with its own expiry), an HR credit or an HR debit — so
     * the balance always shows where its days came from. A legacy row keeps
     * the old allocated arithmetic for plain adjustments and refuses add-on
     * leave until its history has been migrated (otherwise the add-on would
     * vanish into an undecomposed figure).
     *
     * Writes an immutable LeaveBalanceAdjustment plus an audit event.
     *
     * @throws \DomainException if the leave type is system-controlled, the debit exceeds the balance, or the row cannot hold an add-on yet
     */
    public function adjust(
        Employee $employee,
        LeaveType $leaveType,
        string $action,
        float $days,
        string $reason,
        string $remarks,
        User $adjuster,
        ?int $year = null,
        string $category = self::CATEGORY_ADJUSTMENT,
        ?string $addOnType = null,
        ?CarbonInterface $effectiveDate = null,
        ?CarbonInterface $expiresOn = null,
        ?string $internalNote = null,
        ?string $documentPath = null,
    ): LeaveBalanceAdjustment {
        if (! \in_array($action, ['credit', 'debit'], true)) {
            throw new \InvalidArgumentException("Action must be 'credit' or 'debit'.");
        }

        $this->assertNotOwnBalance($employee, $adjuster);

        if (! \in_array($category, [self::CATEGORY_ADJUSTMENT, self::CATEGORY_ADD_ON, self::CATEGORY_CORRECTION], true)) {
            throw new \InvalidArgumentException("Unknown adjustment category '{$category}'.");
        }

        if ($category === self::CATEGORY_ADD_ON && $action !== 'credit') {
            throw new \DomainException('Add-on leave is always a credit.');
        }

        if ($addOnType !== null && ! \in_array($addOnType, self::ADD_ON_TYPES, true)) {
            throw new \DomainException("Unknown add-on type '{$addOnType}'.");
        }

        if ($days <= 0) {
            throw new \DomainException('Days must be greater than zero.');
        }

        if (trim($reason) === '') {
            throw new \DomainException('A reason is required for every leave adjustment.');
        }

        if ($leaveType->is_system_controlled) {
            throw new \DomainException(
                "'{$leaveType->name}' is system-controlled and cannot be manually adjusted."
            );
        }

        // The current LEAVE year, not the calendar year: with a 1 July start
        // those differ from January to June, and an HR correction made in
        // March would otherwise land in a year that has not begun.
        $year ??= app(LeaveYearResolver::class)->legacyYearFor();

        return DB::transaction(function () use ($employee, $leaveType, $action, $days, $reason, $remarks, $adjuster, $year, $category, $addOnType, $effectiveDate, $expiresOn, $internalNote, $documentPath) {
            $leaveYear = app(LeaveYearResolver::class)->forDate(Carbon::create($year, 12, 31));
            $effectiveDate ??= Carbon::today()->between($leaveYear->starts_on, $leaveYear->ends_on)
                ? Carbon::today()
                : $leaveYear->starts_on->copy();

            if ($expiresOn !== null && $expiresOn->lt($effectiveDate)) {
                throw new \DomainException('An add-on cannot expire before it takes effect.');
            }

            $balance = LeaveBalance::firstOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => $year],
                [
                    // The authoritative identity of the year, not just its legacy
                    // integer. Without it the row is only ever found by the
                    // fallback that exists for rows predating leave years.
                    'leave_year_id' => $leaveYear->id,
                    'allocated_days' => 0,
                    'used_days' => 0,
                    'carried_forward_days' => 0,
                    'encashed_days' => 0,
                    'comp_off_credits' => 0,
                ],
            );

            if ($balance->leave_year_id === null && ! $balance->isLedgerBacked()) {
                $balance->forceFill(['leave_year_id' => $leaveYear->id])->save();
            }

            $movements = app(LeaveMovementService::class);
            $ledgerBacked = $movements->ledgerReady($balance);
            $balance->refresh();

            if (! $ledgerBacked && $category === self::CATEGORY_ADD_ON) {
                throw new \DomainException(
                    'This balance has not been migrated to the leave ledger yet, so add-on leave could not be tracked separately. Resolve it in the ledger backfill first.'
                );
            }

            $calculator = app(LeaveBalanceCalculator::class);
            $availableBefore = $calculator->summary($balance)['approved_available'];
            $previousAllocated = (float) $balance->allocated_days;

            if ($action === 'debit' && $days > $availableBefore + 0.001) {
                $shown = max(0, $availableBefore);
                throw new \DomainException("Cannot debit {$days} day(s): only {$shown} day(s) available.");
            }

            $newAllocated = $action === 'credit' ? $previousAllocated + $days : $previousAllocated - $days;

            $adjustment = LeaveBalanceAdjustment::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'leave_year_id' => $leaveYear->id,
                'action' => $action,
                // Tagged so the audit log can tell an HR correction from a
                // carry forward or a regularisation, all three of which land
                // in this same table.
                'source' => 'manual',
                'category' => $category,
                'add_on_type' => $addOnType,
                'effective_date' => $effectiveDate->toDateString(),
                'expires_on' => $expiresOn?->toDateString(),
                'days' => $days,
                'previous_balance' => $previousAllocated,
                'new_balance' => $newAllocated,
                'reason' => $reason,
                'remarks' => $remarks ?: null,
                'internal_note' => $internalNote ?: null,
                'document_path' => $documentPath ?: null,
                'adjusted_by' => $adjuster->id,
                'adjusted_at' => now(),
            ]);

            if ($ledgerBacked) {
                $ledger = app(LeaveLedgerService::class);
                $options = [
                    'source_type' => 'leave_balance_adjustment',
                    'source_id' => $adjustment->id,
                    'reason' => $reason,
                    'actor' => $adjuster,
                    'meta' => array_filter(['category' => $category, 'add_on_type' => $addOnType]),
                ];

                if ($action === 'credit') {
                    $ledger->credit($balance, $category === self::CATEGORY_ADD_ON ? LeaveLedgerEntry::TYPE_ADD_ON : LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT,
                        $days, $effectiveDate, "adjustment:{$adjustment->id}", $options + ['expires_on' => $expiresOn?->toDateString()]);
                } else {
                    $ledger->debit($balance, LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT, $days, $effectiveDate, "adjustment:{$adjustment->id}", $options);
                }

                $balance = $ledger->rebuild($balance);
            } elseif ($action === 'credit') {
                $balance->increment('allocated_days', $days);
            } else {
                $balance->decrement('allocated_days', $days);
            }

            $availableAfter = $calculator->summary($balance->fresh())['approved_available'];

            // The adjustment row is the transaction; this is the entry that puts
            // it in the admin audit log beside every other leave action, with
            // both sides of the change rather than just where it ended up.
            AuditLog::record(
                $adjustment,
                'created',
                ['allocated_days' => $previousAllocated, 'available' => $availableBefore],
                [
                    'allocated_days' => $newAllocated,
                    'available' => $availableAfter,
                    'action' => $action,
                    'category' => $category,
                    'add_on_type' => $addOnType,
                    'source' => 'manual',
                    'days' => $days,
                    'effective_date' => $effectiveDate->toDateString(),
                    'expires_on' => $expiresOn?->toDateString(),
                    'leave_type' => $leaveType->name,
                    'leave_type_id' => $leaveType->id,
                    'leave_year' => $year,
                    'leave_year_id' => $leaveYear->id,
                    'leave_year_label' => $leaveYear->label,
                    'adjusted_by' => $adjuster->id,
                    'remarks' => $remarks ?: null,
                    'ledger_backed' => $ledgerBacked,
                ],
                $reason,
                $employee->id,
                [
                    'module' => 'leave',
                    'category' => 'leave',
                    'event' => match ($category) {
                        self::CATEGORY_ADD_ON => 'LEAVE_ADD_ON_GRANTED',
                        self::CATEGORY_CORRECTION => 'LEAVE_BALANCE_CORRECTED',
                        default => $action === 'credit' ? 'LEAVE_BALANCE_ADJUSTED' : 'LEAVE_DEDUCTED',
                    },
                ],
            );

            return $adjustment;
        });
    }

    /**
     * "Set correct balance": HR states what the available balance should
     * be; the difference is posted as an ordinary credit or debit. The
     * stored figure is never simply overwritten.
     */
    public function setCorrectBalance(
        Employee $employee,
        LeaveType $leaveType,
        float $correctAvailable,
        string $reason,
        string $remarks,
        User $adjuster,
        ?int $year = null,
        ?string $internalNote = null,
        ?CarbonInterface $effectiveDate = null,
        ?string $documentPath = null,
    ): LeaveBalanceAdjustment {
        if ($correctAvailable < 0) {
            throw new \DomainException('A corrected balance cannot be negative.');
        }

        $year ??= app(LeaveYearResolver::class)->legacyYearFor();

        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->first();

        $current = $balance ? app(LeaveBalanceCalculator::class)->summary($balance)['approved_available'] : 0.0;
        $delta = round($correctAvailable - $current, 2);

        if (abs($delta) < 0.005) {
            throw new \DomainException("The available balance is already {$current} day(s).");
        }

        return $this->adjust(
            $employee, $leaveType, $delta > 0 ? 'credit' : 'debit', abs($delta),
            $reason, $remarks, $adjuster, $year, self::CATEGORY_CORRECTION,
            effectiveDate: $effectiveDate,
            internalNote: $internalNote,
            documentPath: $documentPath,
        );
    }

    /**
     * Return an enriched balance summary collection for a given employee and year.
     * Each item includes: balance row, leave type, pending_days computed live.
     */
    /**
     * Record an employee's position in a past leave year.
     *
     * A credit/debit adjustment can only move allocated days, so a historical
     * year could never be stated properly: 28 allocated with 10 used is two
     * facts, and crediting 18 to express it loses both.
     *
     * Deliberately not carry forward. Carry forward derives its own number from
     * the previous year and stays traceable to it; this states what that
     * previous year was. Using this to credit the current year instead would
     * produce days with no year of origin, which is exactly what the
     * carry-forward transaction exists to prevent.
     *
     * @throws \DomainException when the figures cannot describe a real year
     */
    public function setHistoricalBalance(
        Employee $employee,
        LeaveType $leaveType,
        LeaveYear $leaveYear,
        float $allocated,
        // null means the figure is genuinely unknown, which is the normal case
        // for a closed year we only hold a closing balance for. It is recorded
        // as unknown rather than assumed to be zero.
        ?float $used,
        ?float $encashed,
        string $reason,
        ?string $remarks,
        User $actor,
    ): LeaveBalanceAdjustment {
        $this->assertNotOwnBalance($employee, $actor);

        foreach (['allocated' => $allocated, 'used' => $used, 'encashed' => $encashed] as $label => $value) {
            if ($value !== null && $value < 0) {
                throw new \DomainException(ucfirst($label).' days cannot be negative.');
            }
        }

        // Only checkable when both figures are actually known. Comparing an
        // unknown against the allocation would be inventing the very number
        // this method exists to avoid inventing.
        if ($used !== null && $encashed !== null && $used + $encashed > $allocated) {
            // Not merely odd: carry forward would compute a negative eligible
            // figure and clamp it to zero, silently discarding entitlement.
            throw new \DomainException(
                "Used ({$used}) plus encashed ({$encashed}) cannot exceed allocated ({$allocated})."
            );
        }

        if (trim($reason) === '') {
            throw new \DomainException('A historical balance entry needs a reason.');
        }

        // Closed years only. Stating a year rewrites its allocated and used
        // figures wholesale, which is correct for a year that has ended and
        // destructive for the live one: it would discard leave taken, carried
        // and adjusted since. Enforced here so no screen or import can reach it.
        $current = app(LeaveYearResolver::class)->current();
        if ($leaveYear->starts_on->gte($current->starts_on)) {
            throw new \DomainException(
                "Historical balances can only be recorded for closed leave years. {$leaveYear->label} is the current or a future leave year."
            );
        }

        return DB::transaction(function () use ($employee, $leaveType, $leaveYear, $allocated, $used, $encashed, $reason, $remarks, $actor) {
            $balance = LeaveBalance::firstOrNew([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'year' => $leaveYear->legacyYear(),
            ]);

            // A ledger-backed year is described by its ledger; restating it
            // wholesale would silently contradict every posted movement.
            if ($balance->exists && $balance->isLedgerBacked()) {
                throw new \DomainException('This leave year is already on the leave ledger; correct it with a ledger adjustment instead of restating it.');
            }

            $before = [
                'allocated' => (float) ($balance->allocated_days ?? 0),
                'used' => (float) ($balance->used_days ?? 0),
                'encashed' => (float) ($balance->encashed_days ?? 0),
            ];

            $balance->fill([
                // The authoritative identity of the year, not just its legacy
                // integer.
                'leave_year_id' => $leaveYear->id,
                'allocated_days' => $allocated,
                // The column stays 0 because the live engine reads it on every
                // balance; the flag beside it is what stops that 0 being read
                // as a measurement.
                'used_days' => $used ?? 0,
                'used_days_unknown' => $used === null,
                'encashed_days' => $encashed ?? 0,
                'encashed_days_unknown' => $encashed === null,
                // Untouched: days carried into this year came from the year
                // before it and are not part of stating this one.
                'carried_forward_days' => (float) ($balance->carried_forward_days ?? 0),
                'comp_off_credits' => (float) ($balance->comp_off_credits ?? 0),
            ])->save();

            $adjustment = LeaveBalanceAdjustment::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                // The year being stated. adjusted_at is only when HR typed it
                // in, which says nothing about which year it describes.
                'leave_year_id' => $leaveYear->id,
                'action' => $allocated >= $before['allocated'] ? 'credit' : 'debit',
                'source' => 'historical',
                'days' => round(abs($allocated - $before['allocated']), 2),
                'previous_balance' => $before['allocated'],
                'new_balance' => $allocated,
                'reason' => $reason,
                'remarks' => $remarks ?: null,
                'adjusted_by' => $actor->id,
                'adjusted_at' => now(),
            ]);

            // All three figures, both sides. A final allocated number alone
            // cannot describe a year in which usage also changed.
            AuditLog::record(
                $adjustment,
                'leave.historical_balance_set',
                [
                    'allocated_days' => $before['allocated'],
                    'used_days' => $before['used'],
                    'encashed_days' => $before['encashed'],
                ],
                [
                    'allocated_days' => $allocated,
                    // Recorded as the word, not as a number. An audit entry must
                    // never claim a historical figure was known when it was not.
                    'used_days' => $used ?? 'not_available',
                    'encashed_days' => $encashed ?? 'not_available',
                    'used_days_known' => $used !== null,
                    'encashed_days_known' => $encashed !== null,
                    'source' => 'historical',
                    'leave_type' => $leaveType->name,
                    'leave_type_id' => $leaveType->id,
                    'leave_year' => $leaveYear->legacyYear(),
                    'leave_year_id' => $leaveYear->id,
                    'leave_year_label' => $leaveYear->label,
                    // Not derivable without both figures. HR decides the amount
                    // instead of the system guessing it.
                    'eligible_for_carry_forward' => ($used === null || $encashed === null)
                        ? 'not_derivable'
                        : round(max(0, $allocated - $used - $encashed), 2),
                    'performed_by' => $actor->id,
                    'remarks' => $remarks ?: null,
                ],
                $reason,
                $employee->id,
                ['module' => 'leave', 'category' => 'leave', 'event' => 'LEAVE_HISTORICAL_BALANCE_SET'],
            );

            return $adjustment;
        });
    }

    /**
     * Nobody credits, debits or restates their own leave — every screen and
     * bulk path reaches the balance through here, so the rule lives here.
     */
    private function assertNotOwnBalance(Employee $employee, User $actor): void
    {
        if ((int) $employee->user_id === (int) $actor->id) {
            throw new \DomainException('You cannot change your own leave balance.');
        }
    }

    public function getBalanceSummary(Employee $employee, int $year): Collection
    {
        // Get all active leave types
        $allTypes = LeaveType::whereNull('deleted_at')->orderBy('name')->get();

        // Load existing balances keyed by leave_type_id
        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->with('leaveType')
            ->get()
            ->keyBy('leave_type_id');

        // Load pending request days per leave type in one query
        // Bounded by the leave year, not the calendar year. whereYear() counted
        // January to December, so a request in June was attributed to the wrong
        // year for six months of every year.
        $bounds = LeaveYear::where('starts_on', '<=', Carbon::create($year, 7, 1))
            ->orderByDesc('starts_on')->first();
        $startsOn = $bounds?->starts_on ?? Carbon::create($year, 7, 1);
        $endsOn = $bounds?->ends_on ?? Carbon::create($year + 1, 6, 30);

        $pendingDays = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'pending_hr'])
            ->whereDate('start_date', '>=', $startsOn)
            ->whereDate('start_date', '<=', $endsOn)
            ->selectRaw('leave_type_id, SUM(days) as total_pending')
            ->groupBy('leave_type_id')
            ->pluck('total_pending', 'leave_type_id');

        return $allTypes->map(function (LeaveType $type) use ($balances, $pendingDays, $year) {
            $balance = $balances->get($type->id);

            return (object) [
                'leave_type' => $type,
                'leave_type_id' => $type->id,
                'year' => $year,
                'allocated' => $balance ? (float) $balance->allocated_days : 0.0,
                // allocated_days already includes carried days, so showing both
                // columns without subtracting presents the same days twice.
                'fresh' => $balance
                    ? round((float) $balance->allocated_days - (float) $balance->carried_forward_days, 2)
                    : 0.0,
                'used' => $balance ? (float) $balance->used_days : 0.0,
                'carried_forward' => $balance ? (float) $balance->carried_forward_days : 0.0,
                'encashed' => $balance ? (float) $balance->encashed_days : 0.0,
                'available' => $balance ? $balance->available() : 0.0,
                'pending' => (float) ($pendingDays->get($type->id) ?? 0),
                'balance' => $balance,
            ];
        });
    }

    /**
     * Get audit history for a specific employee's leave balance adjustments.
     */
    public function getAdjustmentHistory(Employee $employee, ?int $leaveTypeId = null): Collection
    {
        return LeaveBalanceAdjustment::where('employee_id', $employee->id)
            ->when($leaveTypeId, fn ($q) => $q->where('leave_type_id', $leaveTypeId))
            ->with(['leaveType', 'adjustedByUser'])
            ->latest('adjusted_at')
            ->get();
    }
}
