<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Makes sure an employee holds the leave their policy gives them (Phase 2B).
 *
 * Called when an employee is created, is given a leave policy, moves into an
 * eligible status, enters a new leave year, or is found missing balances.
 * Idempotent: the base entitlement is one ledger credit per employee, type
 * and year under a fixed idempotency key, so running this any number of
 * times — or twice at once — grants it once.
 *
 * Base entitlement is judged on its own bucket. A row that already holds
 * carry forward, accrual or an add-on but no base entitlement still gets
 * its base (the new-year "carry-only row" bug); a row holding an
 * undecomposed opening balance does not, because that opening may already
 * contain it — that row is reported for HR review instead.
 */
class EnsureEmployeeLeaveBalancesService
{
    public const PROVISIONED = 'provisioned';

    public const ALREADY = 'already_provisioned';

    public const RECALCULATED = 'recalculated';

    public const MISMATCH = 'mismatch';

    public const NEEDS_REVIEW = 'needs_hr_review';

    public const ACCRUAL_ONLY = 'accrual_only';

    public const NO_ENTITLEMENT = 'no_entitlement';

    public const INELIGIBLE = 'ineligible';

    public const NOT_CALCULABLE = 'not_calculable';

    private const EPSILON = 0.005;

    public function __construct(
        private readonly LeaveRuleResolver $rules,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveMovementService $movements,
        private readonly LeaveLedgerBackfillService $backfill,
        private readonly LeaveYearResolver $years,
    ) {}

    /**
     * Ensure every leave type for one employee and year.
     *
     * $recalculate: when the posted base differs from what the rules now say
     * (a new policy, an override), replace it — reversal plus a new version.
     * Without it a difference is only reported.
     *
     * @return Collection<int, array<string, mixed>> one row per leave type
     */
    public function ensure(
        Employee $employee,
        ?LeaveYear $year = null,
        ?User $actor = null,
        bool $dryRun = false,
        bool $recalculate = false,
        string $trigger = 'manual',
    ): Collection {
        $year ??= $this->years->current();

        return LeaveType::whereNull('deleted_at')->orderBy('id')->get()
            ->map(fn (LeaveType $type) => $this->ensureType($employee, $type, $year, $actor, $dryRun, $recalculate, $trigger));
    }

    /** @return array<string, mixed> */
    public function ensureType(
        Employee $employee,
        LeaveType $type,
        LeaveYear $year,
        ?User $actor = null,
        bool $dryRun = false,
        bool $recalculate = false,
        string $trigger = 'manual',
    ): array {
        $row = [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'leave_type' => $type->name,
            'leave_year_id' => $year->id,
            'leave_year' => $year->label,
            'expected' => null,
            'current_base' => null,
            'status' => null,
            'message' => '',
        ];

        if ($type->is_system_controlled) {
            return ['status' => self::NO_ENTITLEMENT, 'message' => 'System-controlled type.'] + $row;
        }

        if (! $this->rules->isEligible($employee, $type, $year)) {
            return ['status' => self::INELIGIBLE, 'message' => 'Not eligible in this leave year (status, joining date or gender).'] + $row;
        }

        $entitlement = $this->rules->entitlement($employee, $type, $year);
        $row['expected'] = $entitlement['days'];
        $row['source'] = $entitlement['source'];
        $row['explanation'] = $entitlement['explanation'];

        $existing = $this->existingBalance($employee, $type, $year);
        $row['current_base'] = $existing?->isLedgerBacked() ? $this->liveBase($existing, $year) : null;

        if ($entitlement['issues'] !== []) {
            return ['status' => self::NOT_CALCULABLE, 'message' => implode(' ', $entitlement['issues'])] + $row;
        }

        if ($entitlement['source'] === 'accrual') {
            // Nothing up front; make sure the row exists so it can accrue and be shown.
            if (! $dryRun && $existing === null) {
                $this->openRow($employee, $type, $year);
            }

            return ['status' => self::ACCRUAL_ONLY, 'message' => $entitlement['explanation']] + $row;
        }

        if ($entitlement['days'] === null || $entitlement['days'] <= 0) {
            // Entitlement withdrawn (e.g. an override set to 0): take back the
            // posted base rather than leaving it standing.
            if ($recalculate && ! $dryRun && $existing?->isLedgerBacked() && ($row['current_base'] ?? 0) > self::EPSILON) {
                DB::transaction(function () use ($existing, $year, $actor, $trigger, $entitlement, $row) {
                    foreach ($this->liveBaseEntries($existing, $year) as $entry) {
                        $this->ledger->reverse($entry, 'Entitlement withdrawn ('.$trigger.')', $actor);
                    }
                    $after = $this->ledger->rebuild($existing);
                    $this->audit($after, 'LEAVE_ENTITLEMENT_RECALCULATED', (float) $row['current_base'], 0.0, $entitlement, $actor, $trigger);
                });

                return ['status' => self::RECALCULATED, 'current_base' => 0.0, 'message' => 'Base entitlement withdrawn.'] + $row;
            }

            return ['status' => self::NO_ENTITLEMENT, 'message' => $entitlement['explanation']] + $row;
        }

        if ($dryRun) {
            return $this->plan($existing, $year, $entitlement, $recalculate) + $row;
        }

        return DB::transaction(function () use ($employee, $type, $year, $actor, $recalculate, $trigger, $entitlement, $row) {
            $balance = $this->openRow($employee, $type, $year);

            if (! $balance->isLedgerBacked()) {
                return ['status' => self::NEEDS_REVIEW, 'message' => 'Existing balance has an ambiguous history and is not on the ledger yet; resolve it in the ledger backfill.'] + $row;
            }

            $current = $this->liveBase($balance, $year);
            $row['current_base'] = $current;
            $expected = (float) $entitlement['days'];

            if ($current === 0.0 && $this->hasOpening($balance, $year)) {
                return ['status' => self::NEEDS_REVIEW, 'message' => 'Balance holds an undecomposed opening balance that may already include the entitlement.'] + $row;
            }

            if ($current === 0.0) {
                $this->postBase($balance, $year, $expected, $entitlement, $actor, $trigger, 1);
                $after = $this->ledger->rebuild($balance);
                $this->audit($after, 'LEAVE_ENTITLEMENT_PROVISIONED', 0.0, $expected, $entitlement, $actor, $trigger);

                return ['status' => self::PROVISIONED, 'current_base' => $expected, 'message' => $entitlement['explanation']] + $row;
            }

            if (abs($current - $expected) <= self::EPSILON) {
                return ['status' => self::ALREADY, 'message' => 'Base entitlement already posted.'] + $row;
            }

            if (! $recalculate) {
                return ['status' => self::MISMATCH, 'message' => "Posted base {$current} differs from the expected {$expected}."] + $row;
            }

            foreach ($this->liveBaseEntries($balance, $year) as $entry) {
                $this->ledger->reverse($entry, 'Entitlement recalculated ('.$trigger.')', $actor);
            }
            $version = LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
                ->where('leave_year_id', $year->id)->where('entry_type', LeaveLedgerEntry::TYPE_BASE)
                ->whereNull('reverses_entry_id')->count() + 1;
            $this->postBase($balance, $year, $expected, $entitlement, $actor, $trigger, $version);
            $after = $this->ledger->rebuild($balance);
            $this->audit($after, 'LEAVE_ENTITLEMENT_RECALCULATED', $current, $expected, $entitlement, $actor, $trigger);

            return ['status' => self::RECALCULATED, 'current_base' => $expected, 'message' => "Base changed from {$current} to {$expected}."] + $row;
        });
    }

    /**
     * Every eligible employee missing (or mis-provisioned for) a year.
     *
     * @param  iterable<Employee>|null  $employees
     * @return Collection<int, array<string, mixed>> rows that are not already in order
     */
    public function missing(?LeaveYear $year = null, ?iterable $employees = null): Collection
    {
        $year ??= $this->years->current();
        $employees ??= Employee::whereIn('status', LeaveRuleResolver::ELIGIBLE_STATUSES)->with('user')->get();

        return collect($employees)
            ->flatMap(fn (Employee $e) => $this->ensure($e, $year, dryRun: true)
                ->map(fn (array $r) => $r + ['employee' => $e->user?->name ?? 'Employee #'.$e->id, 'employee_code' => $e->employee_id]))
            ->whereIn('status', [self::PROVISIONED, self::MISMATCH, self::NEEDS_REVIEW, self::NOT_CALCULABLE])
            ->values();
    }

    /**
     * Preview-first bulk provisioning. With $dryRun nothing is written; the
     * summary says what would happen. Applying only ever posts missing base
     * entitlement (PROVISIONED) — mismatches and review cases are reported,
     * never "fixed" by a blind reset.
     *
     * @param  iterable<Employee>  $employees
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function bulk(iterable $employees, LeaveYear $year, ?User $actor, bool $dryRun, string $trigger = 'bulk_provision'): array
    {
        $employees = collect($employees);
        $rows = collect();
        $noPolicy = 0;

        foreach ($employees as $employee) {
            if ($this->rules->policyFor($employee) === null) {
                $noPolicy++;
            }

            $rows = $rows->merge($this->ensure($employee, $year, $actor, $dryRun, false, $trigger)
                ->map(fn (array $r) => $r + ['employee' => $employee->user?->name ?? 'Employee #'.$employee->id, 'employee_code' => $employee->employee_id]));
        }

        $count = fn (string ...$statuses) => $rows->whereIn('status', $statuses)->count();

        return [
            'rows' => $rows->values(),
            'summary' => [
                'selected' => $employees->count(),
                'valid' => $count(self::PROVISIONED),
                'already_processed' => $count(self::ALREADY, self::ACCRUAL_ONLY),
                'skipped' => $count(self::INELIGIBLE, self::NO_ENTITLEMENT),
                'no_policy' => $noPolicy,
                'warnings' => $count(self::MISMATCH, self::NEEDS_REVIEW, self::NOT_CALCULABLE),
                'expected_days' => (int) round($rows->where('status', self::PROVISIONED)->sum('expected')),
            ],
        ];
    }

    /**
     * What ensure() would do, without writing.
     *
     * @return array<string, mixed>
     */
    private function plan(?LeaveBalance $existing, LeaveYear $year, array $entitlement, bool $recalculate): array
    {
        $expected = (float) $entitlement['days'];

        if ($existing === null) {
            return ['status' => self::PROVISIONED, 'message' => 'Will create the balance with base '.$expected.'.'];
        }

        if (! $existing->isLedgerBacked()) {
            $class = $this->backfill->classify($existing)['classification'];

            if ($class !== LeaveLedgerBackfillService::SAFE) {
                return ['status' => self::NEEDS_REVIEW, 'message' => 'Existing balance is '.$class.' for the ledger backfill.'];
            }

            // A SAFE legacy row migrates its remainder as base; judge that.
            $migratedBase = (float) $this->backfill->classify($existing)['migration_amount'];

            return abs($migratedBase - $expected) <= self::EPSILON
                ? ['status' => self::ALREADY, 'current_base' => $migratedBase, 'message' => 'Legacy allocation matches.']
                : ($migratedBase <= self::EPSILON
                    ? ['status' => self::PROVISIONED, 'current_base' => 0.0, 'message' => 'Will post base '.$expected.' (row holds no base entitlement).']
                    : ['status' => $recalculate ? self::RECALCULATED : self::MISMATCH, 'current_base' => $migratedBase, 'message' => "Legacy allocation {$migratedBase} differs from the expected {$expected}."]);
        }

        $current = $this->liveBase($existing, $year);

        if ($current <= self::EPSILON) {
            return $this->hasOpening($existing, $year)
                ? ['status' => self::NEEDS_REVIEW, 'current_base' => 0.0, 'message' => 'Holds an undecomposed opening balance.']
                : ['status' => self::PROVISIONED, 'current_base' => 0.0, 'message' => 'Will post base '.$expected.'.'];
        }

        if (abs($current - $expected) <= self::EPSILON) {
            return ['status' => self::ALREADY, 'current_base' => $current, 'message' => 'Base entitlement already posted.'];
        }

        return ['status' => $recalculate ? self::RECALCULATED : self::MISMATCH, 'current_base' => $current, 'message' => "Posted base {$current} differs from the expected {$expected}."];
    }

    /** The year's row, created ledger-backed if missing; a SAFE legacy row is migrated on touch. */
    private function openRow(Employee $employee, LeaveType $type, LeaveYear $year): LeaveBalance
    {
        // A row created here has no history at all, so it starts on the
        // ledger outright; there is nothing to migrate or audit as migrated.
        $balance = LeaveBalance::firstOrNew(
            ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year->legacyYear()],
        );

        if (! $balance->exists) {
            $balance->forceFill([
                'leave_year_id' => $year->id,
                'allocated_days' => 0, 'used_days' => 0, 'carried_forward_days' => 0,
                'encashed_days' => 0, 'comp_off_credits' => 0,
                'ledger_status' => LeaveBalance::LEDGER_SAFE, 'ledger_migrated_at' => now(),
            ])->save();
        }

        $this->movements->ledgerReady($balance);

        return $balance->fresh();
    }

    private function existingBalance(Employee $employee, LeaveType $type, LeaveYear $year): ?LeaveBalance
    {
        return LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year->legacyYear())
            ->first();
    }

    public function liveBase(LeaveBalance $balance, LeaveYear $year): float
    {
        return round((float) LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->where('entry_type', LeaveLedgerEntry::TYPE_BASE)
            ->sum('days'), 2);
    }

    /** @return Collection<int, LeaveLedgerEntry> */
    private function liveBaseEntries(LeaveBalance $balance, LeaveYear $year): Collection
    {
        return LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->where('entry_type', LeaveLedgerEntry::TYPE_BASE)
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversedBy')
            ->get();
    }

    private function hasOpening(LeaveBalance $balance, LeaveYear $year): bool
    {
        return (float) LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->where('entry_type', LeaveLedgerEntry::TYPE_OPENING)
            ->sum('days') > self::EPSILON;
    }

    private function postBase(LeaveBalance $balance, LeaveYear $year, float $days, array $entitlement, ?User $actor, string $trigger, int $version): void
    {
        $this->ledger->credit($balance, LeaveLedgerEntry::TYPE_BASE, $days, $year->starts_on->copy(),
            "entitlement:{$balance->employee_id}:{$balance->leave_type_id}:{$year->id}:v{$version}", [
                'source_type' => 'leave_entitlement',
                'source_id' => $balance->id,
                'reason' => 'Base entitlement: '.$entitlement['explanation'],
                'meta' => ['trigger' => $trigger, 'source' => $entitlement['source']] + $entitlement['breakdown'],
                'actor' => $actor,
            ]);
    }

    private function audit(LeaveBalance $balance, string $event, float $before, float $after, array $entitlement, ?User $actor, string $trigger): void
    {
        $balance->loadMissing(['leaveType', 'leaveYear']);

        app(AuditService::class)->event(
            $event,
            AuditService::LEAVE,
            $balance,
            old: $event === 'LEAVE_ENTITLEMENT_PROVISIONED' ? null : ['base_days' => $before],
            new: array_filter([
                'employee_id' => $balance->employee_id,
                'leave_type' => $balance->leaveType?->name,
                'leave_type_id' => $balance->leave_type_id,
                'leave_year' => $balance->leaveYear?->label,
                'leave_year_id' => $balance->leave_year_id,
                'leave_policy' => $entitlement['breakdown']['policy'] ?? null,
                'working_pattern' => $entitlement['breakdown']['working_pattern'] ?? null,
                'allocated_days' => $after,
                'base_days' => $after,
                'carried_forward_days' => (float) $balance->carried_forward_days,
                'entitlement_source' => $entitlement['source'],
                'explanation' => $entitlement['explanation'],
                'source' => $trigger,
                'actor_id' => $actor?->id,
            ], fn ($v) => $v !== null),
            reason: $event === 'LEAVE_ENTITLEMENT_PROVISIONED' ? 'Calculated from leave policy ('.$trigger.')' : 'Entitlement recalculated ('.$trigger.')',
            subjectEmployeeId: $balance->employee_id,
            action: $event === 'LEAVE_ENTITLEMENT_PROVISIONED' ? 'leave.entitlement_provisioned' : 'leave.entitlement_recalculated',
        );
    }
}
