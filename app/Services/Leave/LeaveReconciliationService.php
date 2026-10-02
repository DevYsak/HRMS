<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveAccrualLog;
use App\Models\LeaveBalance;
use App\Models\LeaveBulkRun;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Leave-year reconciliation (Phase 2C), e.g. 01 Jul 2026 – 30 Jun 2027.
 *
 * scan() checks every eligible employee and leave type against what the
 * rules say they should hold, and writes nothing. Each row is classified:
 *
 *   SAFE_AUTO_FIX    the fix is mechanical and cannot lose information —
 *                    a missing balance, a missing base entitlement (incl. a
 *                    carry-forward-only new-year row), or a SAFE legacy row
 *                    not yet on the ledger;
 *   NEEDS_HR_REVIEW  the data disagrees with the rules and only HR can say
 *                    which is right — a different base, an undecomposed
 *                    opening balance, accrual that does not match its logs,
 *                    an ambiguous legacy history;
 *   BLOCKED          the data cannot be represented — duplicate rows, a
 *                    BLOCKED legacy row;
 *   OK               nothing to do.
 *
 * reconcile() applies SAFE_AUTO_FIX rows only (all, or the selected keys).
 */
class LeaveReconciliationService
{
    public const OK = 'OK';

    public const SAFE_AUTO_FIX = 'SAFE_AUTO_FIX';

    public const NEEDS_HR_REVIEW = 'NEEDS_HR_REVIEW';

    public const BLOCKED = 'BLOCKED';

    private const EPSILON = 0.005;

    public function __construct(
        private readonly EnsureEmployeeLeaveBalancesService $ensure,
        private readonly LeaveLedgerBackfillService $backfill,
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveRuleResolver $rules,
    ) {}

    /**
     * @param  array{employee_id?: int|null, leave_type_id?: int|null, department_id?: int|null, classification?: string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function scan(LeaveYear $year, array $filters = []): Collection
    {
        $types = LeaveType::whereNull('deleted_at')
            ->when($filters['leave_type_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get();

        $employees = Employee::with(['user', 'leavePolicy', 'department'])
            ->whereIn('status', LeaveRuleResolver::ELIGIBLE_STATUSES)
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->orderBy('id')->get();

        $balances = LeaveBalance::whereIn('employee_id', $employees->pluck('id'))
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->get()
            ->groupBy(fn (LeaveBalance $b) => $b->employee_id.':'.$b->leave_type_id);

        $rows = collect();

        foreach ($employees as $employee) {
            foreach ($types as $type) {
                $row = $this->row($employee, $type, $year, $balances->get($employee->id.':'.$type->id, collect()));

                if ($row !== null) {
                    $rows->push($row);
                }
            }
        }

        return $rows
            ->when($filters['classification'] ?? null, fn (Collection $r, $c) => $r->where('classification', $c))
            ->values();
    }

    /**
     * Apply the SAFE_AUTO_FIX rows. Never touches review or blocked rows.
     *
     * @param  array<int, string>|null  $keys  "employeeId:leaveTypeId" — null for every safe row
     * @return array{run: LeaveBulkRun, fixed: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function reconcile(LeaveYear $year, ?User $actor, ?array $keys = null): array
    {
        $run = LeaveBulkRun::create([
            'kind' => LeaveBulkRun::KIND_RECONCILIATION, 'leave_year_id' => $year->id, 'dry_run' => false,
            'status' => 'running', 'created_by' => $actor?->id, 'parameters' => ['selected' => $keys],
        ]);

        $result = ['fixed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        foreach ($this->scan($year) as $row) {
            if ($keys !== null && ! in_array($row['key'], $keys, true)) {
                continue;
            }

            if ($row['classification'] !== self::SAFE_AUTO_FIX) {
                $result['skipped']++;

                continue;
            }

            try {
                $employee = Employee::findOrFail($row['employee_id']);
                $type = LeaveType::withTrashed()->findOrFail($row['leave_type_id']);

                if ($row['balance_id'] && ($balance = LeaveBalance::find($row['balance_id'])) && ! $balance->isLedgerBacked()) {
                    $this->backfill->migrateIfSafe($balance, $actor);
                }

                $outcome = $this->ensure->ensureType($employee, $type, $year, $actor, trigger: 'reconciliation');

                if (in_array($outcome['status'], [EnsureEmployeeLeaveBalancesService::PROVISIONED, EnsureEmployeeLeaveBalancesService::ALREADY, EnsureEmployeeLeaveBalancesService::ACCRUAL_ONLY], true)) {
                    $result['fixed']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = "{$row['employee']} / {$row['leave_type']}: {$outcome['status']} — {$outcome['message']}";
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "{$row['employee']} / {$row['leave_type']}: ".$e->getMessage();
            }
        }

        $run->update([
            'status' => $result['failed'] > 0 ? 'completed_with_issues' : 'completed',
            'summary' => collect($result)->except('errors')->all() + ['errors' => array_slice($result['errors'], 0, 50)],
            'completed_at' => now(),
        ]);

        app(AuditService::class)->event('LEAVE_RECONCILIATION_RUN', AuditService::LEAVE, $run,
            new: ['leave_year' => $year->label] + collect($result)->except('errors')->all(),
            reason: $keys === null ? 'Reconcile all safe issues' : 'Reconcile selected');

        return ['run' => $run->fresh()] + $result;
    }

    /**
     * @param  Collection<int, LeaveBalance>  $rows
     * @return array<string, mixed>|null
     */
    private function row(Employee $employee, LeaveType $type, LeaveYear $year, Collection $rows): ?array
    {
        $plan = $this->ensure->ensureType($employee, $type, $year, dryRun: true);

        // Nothing expected and nothing held: not a reconciliation concern.
        if ($rows->isEmpty() && in_array($plan['status'], [EnsureEmployeeLeaveBalancesService::INELIGIBLE, EnsureEmployeeLeaveBalancesService::NO_ENTITLEMENT], true)) {
            return null;
        }

        $balance = $rows->sortBy('id')->first();
        $summary = $balance ? $this->calculator->summary($balance) : null;
        $issues = [];
        $classification = self::OK;
        $action = 'None.';
        $flag = function (string $level, string $issue, string $todo) use (&$issues, &$classification, &$action): void {
            $issues[] = $issue;
            // The recommended action follows the most serious issue.
            if ($this->worse($classification, $level) === $level) {
                $classification = $level;
                $action = $todo;
            }
        };

        $duplicate = $rows->count() > 1;
        $missing = $balance === null && in_array($plan['status'], [EnsureEmployeeLeaveBalancesService::PROVISIONED, EnsureEmployeeLeaveBalancesService::ACCRUAL_ONLY], true);
        $carryOnly = $balance && $balance->isLedgerBacked() && (float) $balance->base_days <= self::EPSILON
            && (float) $balance->carried_forward_days > self::EPSILON && (float) $balance->opening_days <= self::EPSILON;
        $accrualMismatch = $balance ? $this->accrualMismatch($balance, $year) : null;

        if ($duplicate) {
            $flag(self::BLOCKED, 'Duplicate balance rows ('.$rows->pluck('id')->implode(', ').').', 'Merge the duplicate rows by hand; nothing is changed automatically.');
        }

        if ($balance && ! $balance->isLedgerBacked()) {
            $class = $this->backfill->classify($balance)['classification'];
            match ($class) {
                LeaveLedgerBackfillService::SAFE => $flag(self::SAFE_AUTO_FIX, 'Legacy balance not yet on the ledger (history accounts for it).', 'Move it onto the ledger.'),
                LeaveLedgerBackfillService::BLOCKED => $flag(self::BLOCKED, 'Legacy balance cannot be represented on the ledger.', 'Correct the stored figures, then re-run.'),
                default => $flag(self::NEEDS_HR_REVIEW, 'Legacy balance history is ambiguous.', 'Review it in the ledger backfill (kept as an opening balance).'),
            };
        }

        if ($missing) {
            $flag(self::SAFE_AUTO_FIX, 'Missing leave balance.', 'Provision it from the policy.');
        } elseif ($carryOnly && in_array($plan['status'], [EnsureEmployeeLeaveBalancesService::PROVISIONED], true)) {
            $flag(self::SAFE_AUTO_FIX, 'New-year row holds carry forward only — base entitlement missing.', 'Post the base entitlement beside the carry forward.');
        } elseif ($plan['status'] === EnsureEmployeeLeaveBalancesService::PROVISIONED && $balance) {
            $flag(self::SAFE_AUTO_FIX, 'Base entitlement missing.', 'Post the base entitlement.');
        } elseif ($plan['status'] === EnsureEmployeeLeaveBalancesService::MISMATCH) {
            $flag(self::NEEDS_HR_REVIEW, 'Base entitlement differs from the policy: '.$plan['message'], 'Confirm the figure; add an override or recalculate.');
        } elseif ($plan['status'] === EnsureEmployeeLeaveBalancesService::NEEDS_REVIEW) {
            $flag(self::NEEDS_HR_REVIEW, $plan['message'], 'Review the opening balance.');
        } elseif ($plan['status'] === EnsureEmployeeLeaveBalancesService::NOT_CALCULABLE) {
            $flag(self::NEEDS_HR_REVIEW, 'Entitlement not calculable: '.$plan['message'], 'Record the missing employee data (e.g. working pattern).');
        }

        if ($accrualMismatch !== null) {
            $flag(self::NEEDS_HR_REVIEW, $accrualMismatch, 'Check the accrual history for this year.');
        }

        return [
            'key' => $employee->id.':'.$type->id,
            'employee_id' => $employee->id,
            'employee' => $employee->user?->name ?? 'Employee #'.$employee->id,
            'employee_code' => $employee->employee_id,
            'department' => $employee->department?->name,
            'policy' => $this->rules->policyFor($employee)?->name,
            'leave_type_id' => $type->id,
            'leave_type' => $type->name,
            'balance_id' => $balance?->id,
            'expected_base' => $plan['expected'],
            'actual_base' => $balance?->isLedgerBacked() ? (float) $balance->base_days : null,
            'carry_forward' => $summary['carry_forward'] ?? 0.0,
            'add_on' => $summary['add_on'] ?? 0.0,
            'accrual' => $summary['accrued'] ?? 0.0,
            'used' => $summary['used'] ?? 0.0,
            'pending' => $summary['pending'] ?? 0.0,
            'expired' => $summary['expired'] ?? 0.0,
            'available' => $summary['approved_available'] ?? 0.0,
            'missing' => $missing,
            'duplicate' => $duplicate,
            'incorrect_accrual' => $accrualMismatch !== null,
            'carry_only' => $carryOnly,
            'classification' => $classification,
            'issues' => $issues,
            'recommended_action' => $action,
        ];
    }

    /** Accrual posted to the year that does not match its accrual logs, described; null when consistent. */
    private function accrualMismatch(LeaveBalance $balance, LeaveYear $year): ?string
    {
        if (! $balance->isLedgerBacked()) {
            return null;
        }

        $logged = round((float) LeaveAccrualLog::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->get()
            ->filter(fn (LeaveAccrualLog $log) => $year->contains(Carbon::create($log->year, $log->month, 1)))
            ->sum('days_credited'), 2);

        $posted = round((float) LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)
            ->sum('days'), 2);

        return abs($logged - $posted) > self::EPSILON
            ? "Accrual posted ({$posted}) differs from the accrual log ({$logged})."
            : null;
    }

    private function worse(string $a, string $b): string
    {
        $rank = [self::OK => 0, self::SAFE_AUTO_FIX => 1, self::NEEDS_HR_REVIEW => 2, self::BLOCKED => 3];

        return $rank[$b] > $rank[$a] ? $b : $a;
    }
}
