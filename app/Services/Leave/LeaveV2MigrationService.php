<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveV2Migration;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Security\PeopleScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves each employee's paid leave into Leave V2's single Earned Leave
 * balance — once, audited, and never more than they hold today.
 *
 * The source is the employee's CURRENT authoritative CSL balance for the
 * leave year (approved available, from the calculator, which must agree
 * with the ledger), or an explicit HR override. Sick, Annual and every other
 * historical balance are shown but never added; Comp Off and Special Leave
 * stay separate; existing requests are never touched. The Earned Leave
 * balance receives one opening ledger entry for exactly that amount (none
 * for zero) and a LeaveV2Migration row records source, actor, time and key.
 *
 * Anything that makes the source uncertain stops that employee (STOP) rather
 * than guess. Re-running skips everyone already migrated.
 */
class LeaveV2MigrationService
{
    public const READY = 'READY';

    public const MIGRATED = 'MIGRATED';

    public const STOP = 'STOP';

    /** The Earned Leave annual entitlement the policy must give (rule 1). */
    public const DEFAULT_ENTITLEMENT = 12.0;

    public const PENDING = ['pending', 'pending_hr', 'more_info_requested', 'awaiting_employee_confirmation'];

    private const EPSILON = 0.005;

    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveBalanceSource $sources,
        private readonly LeavePolicyAssignmentResolver $assignments,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveMovementService $movements,
        private readonly LeaveYearResolver $years,
    ) {}

    /** The leave type whose current balance becomes Earned Leave (production: CSL). */
    public function sourceType(string $code = ConexusLeavePolicyService::CSL_CODE): ?LeaveType
    {
        return LeaveType::where('code', $code)->whereNull('deleted_at')->first();
    }

    /**
     * One row per working employee: today's balances, the proposal and
     * whether it is safe. Writes nothing.
     *
     * @param  array<int, string>  $employeeCodes  limit to these employee codes (empty = everyone)
     * @param  array<string, array{available: float, reason: string}>  $overrides  employee code => HR override
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(LeaveYear $year, ?LeaveType $source, array $employeeCodes = [], array $overrides = [], float $expectedEntitlement = self::DEFAULT_ENTITLEMENT): Collection
    {
        return Employee::with(['user'])
            ->whereIn('status', PeopleScope::WORKING_STATUSES)
            ->when($employeeCodes !== [], fn ($q) => $q->whereIn('employee_id', $employeeCodes))
            ->orderBy('employee_id')
            ->get()
            ->map(fn (Employee $employee) => $this->planFor($employee, $year, $source, $overrides[$employee->employee_id] ?? null, $expectedEntitlement));
    }

    /**
     * @param  array{available: float, reason: string}|null  $override
     * @return array<string, mixed>
     */
    public function planFor(Employee $employee, LeaveYear $year, ?LeaveType $source, ?array $override = null, float $expectedEntitlement = self::DEFAULT_ENTITLEMENT): array
    {
        $on = $this->onDate($year);
        $policy = $this->assignments->policyFor($employee, $on);
        $earned = $this->sources->poolOf($policy);
        $balances = $this->balances($employee, $year);
        $summary = fn (LeaveBalance $b) => (float) $this->calculator->summary($b)['approved_available'];
        $sum = fn (callable $match) => round($balances->filter(fn (LeaveBalance $b) => $b->leaveType && $match($b->leaveType))->sum($summary), 2);
        $notSource = fn (LeaveType $t) => $source === null || $t->id !== $source->id;

        $sourceRows = $source ? $balances->where('leave_type_id', $source->id)->values() : collect();
        $sourceBalance = $sourceRows->first();
        $sourceAvailable = $sourceBalance ? $summary($sourceBalance) : null;
        $pendingLegacy = LeaveRequest::where('employee_id', $employee->id)->whereIn('status', self::PENDING)->get();
        $pendingOnSource = $source ? $pendingLegacy->filter(fn (LeaveRequest $r) => $r->balanceTypeId() === $source->id)->count() : 0;
        $entitlement = $earned ? $this->entitlement($policy, $earned) : null;

        $row = [
            'employee_id' => $employee->id,
            'employee' => $employee->user?->name ?? 'Employee #'.$employee->id,
            'code' => $employee->employee_id,
            'policy' => $policy?->name,
            'earned_type_id' => $earned?->id,
            'source_type_id' => $source?->id,
            'source_balance_id' => $sourceBalance?->id,
            'csl_available' => $sourceAvailable,
            'sick_available' => $sum(fn (LeaveType $t) => $notSource($t) && ($t->category === 'sick' || str_contains(strtolower($t->name), 'sick'))),
            'annual_historical' => $sum(fn (LeaveType $t) => $notSource($t)
    && ($earned === null || $t->id !== $earned->id)
    && ($t->category === 'annual' || str_starts_with(strtolower($t->name), 'annual'))
            ),
            'comp_off' => $sum(fn (LeaveType $t) => LeaveCategory::of($t) === LeaveCategory::COMP_OFF || $t->category === 'comp_off'),
            'special' => $sum(fn (LeaveType $t) => LeaveCategory::of($t) === LeaveCategory::SPECIAL),
            'pending_legacy' => $pendingLegacy->count(),
            'proposed' => null,
            'entitlement' => $entitlement,
            'difference' => null,
            'override' => $override,
            'status' => self::STOP,
            'reason' => '',
        ];

        $stop = fn (string $reason) => ['status' => self::STOP, 'reason' => $reason] + $row;

        // Already done: report, and flag a source that has moved since.
        if ($earned && ($record = LeaveV2Migration::where('employee_id', $employee->id)->where('earned_leave_type_id', $earned->id)->where('leave_year_id', $year->id)->first())) {
            $drift = $sourceAvailable !== null && $record->override_reason === null && abs($sourceAvailable - (float) $record->source_available) > self::EPSILON;

            return [
                'status' => self::MIGRATED,
                'proposed' => (float) $record->migrated_available,
                'difference' => $entitlement !== null ? round((float) $record->migrated_available - $entitlement, 2) : null,
                'reason' => 'Migrated '.$record->created_at?->format('d M Y H:i').' ('.$record->migration_key.')'
                    .($drift ? "; CSL has changed since ({$record->source_available} → {$sourceAvailable}) — Earned Leave was not updated" : ''),
            ] + $row;
        }

        if (! $policy) {
            return $stop('No leave policy resolves for this employee.');
        }
        if (! $earned) {
            return $stop("Policy '{$policy->name}' has no Earned Leave type yet — configure it before migrating.");
        }
        if ($entitlement === null || abs($entitlement - $expectedEntitlement) > self::EPSILON) {
            return $stop("Policy '{$policy->name}' gives ".($entitlement === null ? 'no fixed' : $entitlement)." Earned Leave a year; expected {$expectedEntitlement}.");
        }
        if ($source !== null && $earned->id === $source->id) {
            return $stop('The Earned Leave type is the source type itself; choose a separate Earned Leave type.');
        }
        if ($this->earnedHasActivity($employee, $earned, $year)) {
            return $stop('Earned Leave already holds credits or usage for '.$year->label.' — resolve manually; the migration never adds on top.');
        }
        if ($pendingOnSource > 0) {
            return $stop("{$pendingOnSource} pending ".($source?->name ?? 'source').' request(s) still reserve the balance — decide them first, then re-run.');
        }

        if ($override !== null) {
            $amount = round((float) $override['available'], 2);

            return [
                'status' => self::READY,
                'proposed' => $amount,
                'difference' => round($amount - $entitlement, 2),
                'reason' => 'HR override: '.$override['reason'],
            ] + $row;
        }

        if ($source === null) {
            return $stop('No source leave type (CSL) exists — supply an HR override.');
        }
        if ($sourceRows->count() > 1) {
            return $stop('More than one '.$source->name.' balance for '.$year->label.' — ambiguous source.');
        }
        if (! $sourceBalance) {
            return $stop('No '.$source->name.' balance for '.$year->label.' — supply an HR override or provision it first.');
        }
        if (! $sourceBalance->isLedgerBacked()) {
            return $stop($source->name.' balance is not on the leave ledger — not authoritative.');
        }
        $ledgerTotal = $this->ledgerTotal($employee->id, $source->id, $year->id);
        if (abs($sourceAvailable - $ledgerTotal) > self::EPSILON) {
            return $stop($source->name.' does not reconcile: calculator '.$sourceAvailable.' vs ledger '.$ledgerTotal.'.');
        }
        if ($sourceAvailable < -self::EPSILON) {
            return $stop($source->name.' is negative ('.$sourceAvailable.') — resolve it before migrating.');
        }

        $amount = round(max(0.0, $sourceAvailable), 2);

        return [
            'status' => self::READY,
            'proposed' => $amount,
            'difference' => round($amount - $entitlement, 2),
            'reason' => 'Current '.$source->name.' available carried into Earned Leave.',
        ] + $row;
    }

    /**
     * Migrate one READY employee in a single transaction: Earned Leave opening
     * ledger entry (when above zero), the migration record and the audit
     * event, then a reconciliation check — any failure rolls all of it back.
     *
     * @param  array<string, mixed>  $planned  a row from plan()
     * @return array<string, mixed> the row, with status MIGRATED or STOP
     */
    public function apply(array $planned, LeaveYear $year, User $actor, float $expectedEntitlement = self::DEFAULT_ENTITLEMENT): array
    {
        return DB::transaction(function () use ($planned, $year, $actor, $expectedEntitlement) {
            $employee = Employee::with('user')->findOrFail($planned['employee_id']);
            $source = $planned['source_type_id'] ? LeaveType::find($planned['source_type_id']) : null;
            // Re-judged inside the transaction: nothing moves on a stale preview.
            $row = $this->planFor($employee, $year, $source, $planned['override'], $expectedEntitlement);
            if ($row['status'] !== self::READY) {
                return $row;
            }

            $earned = LeaveType::findOrFail($row['earned_type_id']);
            $amount = (float) $row['proposed'];
            $key = LeaveV2Migration::keyFor($employee->id, $earned->id, $year->id);
            $balance = $this->movements->balanceFor($employee->id, $earned->id, $year->starts_on);
            $this->movements->ledgerReady($balance);

            $entry = null;
            if ($amount > self::EPSILON) {
                $entry = $this->ledger->credit($balance->fresh(), LeaveLedgerEntry::TYPE_OPENING, $amount, $year->starts_on, $key, [
                    'source_type' => 'leave_v2_migration',
                    'reason' => $row['override'] ? 'Leave V2 migration (HR override): '.$row['override']['reason'] : 'Leave V2 migration: current '.($source?->name ?? 'balance').' available',
                    'actor' => $actor,
                    'meta' => array_filter([
                        'source_leave_type_id' => $source?->id,
                        'source_balance_id' => $row['source_balance_id'],
                        'source_available' => $row['csl_available'],
                        'override' => $row['override'],
                    ], fn ($v) => $v !== null),
                ]);
                $this->ledger->rebuild($balance->fresh());
            }

            $record = LeaveV2Migration::create([
                'employee_id' => $employee->id,
                'leave_year_id' => $year->id,
                'earned_leave_type_id' => $earned->id,
                'earned_balance_id' => $balance->id,
                'source_leave_type_id' => $source?->id,
                'source_balance_id' => $row['source_balance_id'],
                'source_available' => $row['csl_available'],
                'migrated_available' => $amount,
                'policy_entitlement' => $row['entitlement'],
                'override_reason' => $row['override']['reason'] ?? null,
                'ledger_entry_id' => $entry?->id,
                'effective_date' => Carbon::today()->toDateString(),
                'migration_key' => $key,
                'meta' => ['sick_available' => $row['sick_available'], 'annual_historical' => $row['annual_historical'], 'comp_off' => $row['comp_off'], 'special' => $row['special']],
                'created_by' => $actor->id,
            ]);

            // Reconciles: the Earned Leave balance is exactly the amount, and its ledger agrees.
            $after = (float) $this->calculator->summary($balance->fresh())['approved_available'];
            $ledgerTotal = $this->ledgerTotal($employee->id, $earned->id, $year->id);
            if (abs($after - $amount) > self::EPSILON || abs($ledgerTotal - $amount) > self::EPSILON) {
                throw new RuntimeException("Earned Leave for {$employee->employee_id} does not reconcile after migration (balance {$after}, ledger {$ledgerTotal}, expected {$amount}).");
            }

            app(AuditService::class)->event('LEAVE_V2_MIGRATED', AuditService::LEAVE, $record,
                new: [
                    'earned_leave_type' => $earned->name,
                    'earned_available' => $amount,
                    'policy_entitlement' => $row['entitlement'],
                    'source_leave_type' => $source?->name,
                    'source_available' => $row['csl_available'],
                    'override' => $row['override'],
                    'ledger_entry_id' => $entry?->id,
                    'migration_key' => $key,
                ],
                reason: $row['reason'], subjectEmployeeId: $employee->id, actor: $actor);

            return ['status' => self::MIGRATED, 'reason' => 'Migrated: Earned Leave opening '.$amount.($entry ? " (ledger #{$entry->id})" : ' (no credit — zero balance)')] + $row;
        });
    }

    /**
     * Why Earned Leave must not be provisioned or accrued for this employee
     * yet — null when it may. Before the switch to Leave V2 nobody's Earned
     * Leave is granted except by the migration; after it, an employee still
     * holding a CSL balance this year waits for the migration. A migrated
     * employee's year is set by the migration, never by a fresh base.
     */
    public function holdsEarned(Employee $employee, LeaveType $type, LeaveYear $year): ?string
    {
        $pool = $this->sources->earnedPool($employee, $this->onDate($year));
        if ($pool === null || $pool->id !== $type->id) {
            return null;
        }

        if (LeaveV2Migration::done($employee->id, $type->id, $year->id)) {
            return 'Earned Leave for this year was set by the Leave V2 migration.';
        }

        // Migrated in an earlier year: from then on Earned Leave runs normally
        // (monthly accrual and the year-end carry forward).
        if (LeaveV2Migration::where('employee_id', $employee->id)->where('earned_leave_type_id', $type->id)
            ->whereHas('leaveYear', fn ($q) => $q->where('starts_on', '<', $year->starts_on))->exists()) {
            return null;
        }

        if (! LeavePolicyEngine::enabled()) {
            return 'Awaiting the Leave V2 migration (Leave V2 is off).';
        }

        $source = $this->sourceType();

        return $source && $this->balances($employee, $year)->contains('leave_type_id', $source->id)
            ? 'Awaiting the Leave V2 migration of this employee\'s '.$source->name.' balance.'
            : null;
    }

    /** @return Collection<int, LeaveBalance> the employee's balances in the year */
    private function balances(Employee $employee, LeaveYear $year): Collection
    {
        return LeaveBalance::with(['leaveType' => fn ($q) => $q->withTrashed()])
            ->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere(fn ($w) => $w->whereNull('leave_year_id')->where('year', $year->legacyYear())))
            ->get();
    }

    private function earnedHasActivity(Employee $employee, LeaveType $earned, LeaveYear $year): bool
    {
        if (LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $earned->id)->where('leave_year_id', $year->id)->exists()) {
            return true;
        }

        return $this->balances($employee, $year)->where('leave_type_id', $earned->id)
            ->contains(fn (LeaveBalance $b) => abs((float) $b->allocated_days) > self::EPSILON || abs((float) $b->used_days) > self::EPSILON);
    }

    private function ledgerTotal(int $employeeId, int $typeId, int $yearId): float
    {
        return round((float) LeaveLedgerEntry::where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('leave_year_id', $yearId)->sum('days'), 2);
    }

    /** The policy's fixed annual Earned Leave entitlement (rule, else the type's allocation). */
    private function entitlement(LeavePolicy $policy, LeaveType $earned): ?float
    {
        $rule = LeavePolicyRule::where('leave_policy_id', $policy->id)->where('leave_type_id', $earned->id)->where('is_active', true)->first();
        $days = $rule?->fixed_days ?? $earned->annual_allocation_days;

        return $days === null ? null : round((float) $days, 2);
    }

    /** Today when inside the year, else its first day. */
    private function onDate(LeaveYear $year): Carbon
    {
        $today = Carbon::today();

        return $today->between($year->starts_on, $year->ends_on) ? $today : Carbon::parse($year->starts_on);
    }
}
