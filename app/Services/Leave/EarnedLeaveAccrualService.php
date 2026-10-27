<?php

namespace App\Services\Leave;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Models\LeaveV2Migration;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leave V2 Earned Leave accrual: 1 day for every COMPLETED month of the
 * Jul–Jun leave year (12 a year), credited on the ledger effective the last
 * day of the month — the only source of Earned Leave entitlement.
 *
 * Every month has a deterministic key
 * (earned-accrual:{employee}:{leave_year}:{YYYY-MM}), so running it any
 * number of times — scheduled, by hand, or catching up missed months —
 * credits each month once.
 *
 * An employee who already holds Earned Leave (a migrated opening, an
 * up-front grant) accrues only from their explicit "Monthly Accrual Starts
 * From" date (LeaveAccrualTransitionService); without one they are NEEDS
 * REVIEW. The months an earlier balance stands for are never inferred, and
 * that balance is never touched. Everyone else accrues from their own
 * eligibility (joining date, policy assignment).
 *
 * Anything that makes the months uncertain is reported (NEEDS REVIEW or
 * BLOCKED) and left alone — never repaired silently. The preview writes
 * nothing.
 */
class EarnedLeaveAccrualService
{
    public const READY = 'READY';

    public const NO_CHANGE = 'NO CHANGE';

    public const REVIEW = 'NEEDS REVIEW';

    public const BLOCKED = 'BLOCKED';

    public const KEY_PREFIX = 'earned-accrual';

    public const SOURCE_TYPE = 'earned_leave_accrual';

    public const ANNUAL_DAYS = 12.0;

    public const MONTHLY_DAYS = 1.0;

    /** Statuses whose completed months can still be earned (a leaver needs a last working day). */
    private const LEFT = ['resigned', 'terminated', 'absconded', 'inactive', 'archived'];

    private const EPSILON = 0.005;

    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeavePolicyAssignmentResolver $assignments,
        private readonly LeaveBalanceSource $sources,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveMovementService $movements,
        private readonly LeaveYearResolver $years,
        private readonly ConexusCslAccrualService $months,
        private readonly LeaveAccrualTransitionService $transitions,
    ) {}

    /** The leave year an as-of date accrues for: on 1 July that is the year just finished. */
    public function yearFor(Carbon $asOf): LeaveYear
    {
        return $this->years->forDate($asOf->copy()->subDay());
    }

    /**
     * One row per employee whose policy has an Earned Leave balance (and,
     * with $everyone, every other working employee too). Writes nothing.
     *
     * @param  array<int, string>  $employeeCodes
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(Carbon $asOf, ?LeaveYear $year = null, array $employeeCodes = [], bool $everyone = false): Collection
    {
        $year ??= $this->yearFor($asOf);

        return Employee::with(['user', 'exitRecord'])
            ->when($employeeCodes !== [], fn ($q) => $q->whereIn('employee_id', $employeeCodes))
            ->orderBy('employee_id')
            ->get()
            ->map(fn (Employee $employee) => $this->planFor($employee, $year, $asOf))
            ->filter(fn (array $row) => $everyone || $employeeCodes !== [] || $row['earned_type_id'] !== null)
            ->values();
    }

    /** @return array<string, mixed> */
    public function planFor(Employee $employee, LeaveYear $year, Carbon $asOf): array
    {
        $on = $asOf->copy()->subDay()->between($year->starts_on, $year->ends_on) ? $asOf->copy()->subDay() : Carbon::parse($year->ends_on);
        $policy = $this->assignments->policyFor($employee, $on);
        $earned = $this->sources->poolOf($policy);

        $row = [
            'employee_id' => $employee->id,
            'employee' => $employee->user?->name ?? 'Employee #'.$employee->id,
            'code' => $employee->employee_id,
            'policy' => $policy?->name,
            'earned_type_id' => $earned?->id,
            'year_id' => $year->id,
            'year' => $year->label,
            'as_of' => $asOf->toDateString(),
            'available' => null, 'opening' => null, 'accrued' => null, 'used' => null, 'pending' => null,
            'transition' => null, 'suggested_start' => null,
            'expected_months' => [], 'expected' => null, 'credited_months' => [],
            'due_months' => [], 'proposed' => 0.0,
            'status' => self::BLOCKED, 'reason' => '',
        ];
        // By reference: a stop reports the figures gathered so far.
        $stop = function (string $status, string $reason) use (&$row): array {
            return ['status' => $status, 'reason' => $reason] + $row;
        };

        if (! $policy) {
            return $stop(self::BLOCKED, 'No leave policy resolves for this employee.');
        }
        if (! $earned) {
            return $stop(self::BLOCKED, "Policy '{$policy->name}' has no Earned Leave balance.");
        }

        $balance = $this->balance($employee, $earned, $year);
        if ($balance) {
            $s = $this->calculator->summary($balance);
            $row = ['available' => (float) $s['approved_available'], 'opening' => (float) $s['opening'], 'accrued' => (float) $s['accrued'],
                'used' => (float) $s['used'], 'pending' => (float) $s['pending']] + $row;
        }

        // The policy must say: 12 a year, accrued monthly at 1 a month.
        $rule = LeavePolicyRule::where('leave_policy_id', $policy->id)->where('leave_type_id', $earned->id)->where('is_active', true)->first();
        if (($why = $this->ruleProblem($rule)) !== null) {
            return $stop(self::BLOCKED, $why);
        }
        // Before the switch to Leave V2, an employee's Earned Leave starts with their migration.
        $held = app(LeaveV2MigrationService::class)->holdsEarned($employee, $earned, $year);
        if ($held !== null && ! LeaveV2Migration::done($employee->id, $earned->id, $year->id)) {
            return $stop(self::BLOCKED, $held);
        }
        if ($rule->probation_restricted && $this->status($employee) === 'probation') {
            return $stop(self::REVIEW, 'Probation-restricted Earned Leave and the employee is on probation — the business rule for probation months is not defined.');
        }

        // Which completed months the employee has earned.
        $joiningRule = $rule->joining_month_rule ?: LeavePolicyRule::JOINING_HALF_MONTH;
        if (! $employee->exitRecord?->last_working_day && in_array($this->status($employee), self::LEFT, true)) {
            return $stop(self::BLOCKED, 'Status is '.$this->status($employee).' but no last working day is recorded.');
        }
        [$eligible, $problem] = $this->months->eligibleMonths($employee, $year, $joiningRule);
        if ($problem !== null) {
            return $stop(self::BLOCKED, $problem);
        }

        // What is already there.
        $entries = $balance ? LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $earned->id)->where('leave_year_id', $year->id)->get() : collect();
        $live = $entries->whereNull('reverses_entry_id')->reject(fn (LeaveLedgerEntry $e) => $entries->contains('reverses_entry_id', $e->id));
        $ours = $entries->filter(fn (LeaveLedgerEntry $e) => str_starts_with((string) $e->idempotency_key, self::KEY_PREFIX.':'));
        $credited = $live->filter(fn (LeaveLedgerEntry $e) => str_starts_with((string) $e->idempotency_key, self::KEY_PREFIX.':'))->map(fn ($e) => $e->meta['month'] ?? null)->filter()->values()->all();
        $row['credited_months'] = $credited;

        if ($live->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)->reject(fn ($e) => str_starts_with((string) $e->idempotency_key, self::KEY_PREFIX.':'))->isNotEmpty()) {
            return $stop(self::REVIEW, 'Earned Leave holds accrual credits from another process — review before automatic accrual.');
        }
        if ($ours->contains(fn ($e) => $e->reverses_entry_id !== null)) {
            return $stop(self::REVIEW, 'A monthly accrual was reversed by hand — re-crediting it needs an HR decision.');
        }
        if (count($credited) !== count(array_unique($credited))) {
            return $stop(self::REVIEW, 'The same month is accrued twice.');
        }

        // When automatic accrual takes over. An employee who already holds
        // Earned Leave (a migrated opening, an up-front grant) needs an explicit
        // "Monthly Accrual Starts From": the months that balance stands for are
        // never inferred, and it is never changed. Anyone else accrues from
        // their own eligibility (joining date, policy assignment).
        $transition = $this->transitions->startsOn($employee);
        $row['transition'] = $transition?->toDateString();
        $held = $live->whereIn('entry_type', [LeaveLedgerEntry::TYPE_BASE, LeaveLedgerEntry::TYPE_OPENING])
            ->filter(fn (LeaveLedgerEntry $e) => abs((float) $e->days) > self::EPSILON);
        $migration = LeaveV2Migration::where('employee_id', $employee->id)->where('earned_leave_type_id', $earned->id)->where('leave_year_id', $year->id)->first();

        if ($transition === null && ($held->isNotEmpty() || $migration !== null)) {
            $row['suggested_start'] = $migration ? $this->suggestedStart($migration, $year) : null;

            return $stop(self::REVIEW, $this->heldReason($held, $migration, $row['suggested_start']));
        }
        if ($transition !== null) {
            $late = $held->filter(fn (LeaveLedgerEntry $e) => Carbon::parse($e->effective_date)->gte($transition));
            if ($late->isNotEmpty()) {
                return $stop(self::REVIEW, 'An up-front or opening credit is dated on or after Monthly Accrual Starts From ('.$transition->toDateString().') — accruing monthly as well would grant it twice.');
            }
            $startMonth = $transition->format('Y-m');
            $before = array_values(array_filter($credited, fn (string $m) => $m < $startMonth));
            if ($before !== []) {
                return $stop(self::REVIEW, 'Accrued before Monthly Accrual Starts From: '.implode(', ', $before).'.');
            }
            $eligible = array_values(array_filter($eligible, fn (string $m) => $m >= $startMonth));
        }

        $expected = array_values(array_filter($eligible, fn (string $m) => $this->months->monthEnd($m)->lt($asOf)));
        $row['expected_months'] = $expected;
        $row['expected'] = round(count($expected) * self::MONTHLY_DAYS, 2);

        $stray = array_diff($credited, $eligible);
        if ($stray !== []) {
            return $stop(self::REVIEW, 'Accrued for month(s) the employee did not earn: '.implode(', ', $stray).'.');
        }

        $due = array_values(array_diff($expected, $credited));
        // Never more than 12 monthly credits a year.
        $room = (int) floor((self::ANNUAL_DAYS - count($credited)) / self::MONTHLY_DAYS);
        $due = array_slice($due, 0, max(0, $room));

        $row['due_months'] = $due;
        $row['proposed'] = round(count($due) * self::MONTHLY_DAYS, 2);

        return $due === []
            ? ['status' => self::NO_CHANGE, 'reason' => 'Up to date: '.count($expected).' completed eligible month(s) credited.'] + $row
            : ['status' => self::READY, 'reason' => 'Credit '.implode(', ', $due).'.'] + $row;
    }

    /**
     * Credit one READY employee's due months — re-judged inside the
     * transaction, one ledger entry per month under its deterministic key.
     *
     * @param  array<string, mixed>  $planned
     * @return array<string, mixed>
     */
    public function apply(array $planned, ?User $actor = null, string $trigger = 'manual'): array
    {
        return DB::transaction(function () use ($planned, $actor, $trigger) {
            $employee = Employee::with(['user', 'exitRecord'])->findOrFail($planned['employee_id']);
            $year = LeaveYear::findOrFail($planned['year_id']);
            // A second run (retry, overlap) waits here, then re-plans on what the first wrote.
            if ($planned['earned_type_id'] ?? null) {
                $existing = $this->balance($employee, LeaveType::findOrFail($planned['earned_type_id']), $year);
                if ($existing) {
                    LeaveBalance::whereKey($existing->id)->lockForUpdate()->first();
                }
            }
            $row = $this->planFor($employee, $year, Carbon::parse($planned['as_of']));
            if ($row['status'] !== self::READY) {
                return $row;
            }

            $earned = LeaveType::findOrFail($row['earned_type_id']);
            $balance = $this->movements->balanceFor($employee->id, $earned->id, Carbon::parse($year->starts_on));
            $this->movements->ledgerReady($balance);

            $entryIds = [];
            foreach ($row['due_months'] as $month) {
                $entry = $this->ledger->credit($balance->fresh(), LeaveLedgerEntry::TYPE_ACCRUAL, self::MONTHLY_DAYS, $this->months->monthEnd($month),
                    $this->keyFor($employee, $year, $month), [
                        'source_type' => self::SOURCE_TYPE,
                        'reason' => 'Earned Leave accrual for '.Carbon::parse($month.'-01')->format('F Y'),
                        'actor' => $actor,
                        'meta' => ['month' => $month, 'trigger' => $trigger, 'actor' => $actor ? 'user:'.$actor->id : 'system:'.$trigger],
                    ]);
                $entryIds[] = $entry->id;
            }
            $this->ledger->rebuild($balance->fresh());

            app(AuditService::class)->event('LEAVE_EARNED_ACCRUED', AuditService::LEAVE, $balance->fresh(),
                new: ['months' => $row['due_months'], 'days' => $row['proposed'], 'ledger_entry_ids' => $entryIds, 'leave_year' => $year->label, 'trigger' => $trigger,
                    'monthly_accrual_starts_from' => $row['transition'],
                    'actor' => $actor ? $actor->email : 'system'],
                reason: $actor ? 'Earned Leave accrual run by '.$actor->name : 'System: Earned Leave accrual ('.$trigger.')',
                subjectEmployeeId: $employee->id, actor: $actor);

            return ['status' => 'CREDITED', 'reason' => 'Credited '.implode(', ', $row['due_months']).'.'] + $row;
        });
    }

    /**
     * A start date HR may consider, never applied: the month after those the
     * migration's source (CSL) base and monthly credits stood for when it ran.
     * Null when the opening came from an HR override or the count is unclear.
     */
    public function suggestedStart(LeaveV2Migration $migration, LeaveYear $year): ?string
    {
        if ($migration->override_reason !== null || $migration->source_leave_type_id === null) {
            return null;
        }

        $days = (float) LeaveLedgerEntry::where('employee_id', $migration->employee_id)
            ->where('leave_type_id', $migration->source_leave_type_id)
            ->where('leave_year_id', $migration->leave_year_id)
            ->whereIn('entry_type', [LeaveLedgerEntry::TYPE_BASE, LeaveLedgerEntry::TYPE_ACCRUAL])
            ->where('created_at', '<=', $migration->created_at)
            ->sum('days');
        $months = (int) round($days / self::MONTHLY_DAYS);

        return $months >= 0 && $months < 12 && abs($days - $months * self::MONTHLY_DAYS) < self::EPSILON
            ? Carbon::parse($year->starts_on)->addMonthsNoOverflow($months)->toDateString()
            : null;
    }

    /** Why an employee holding Earned Leave waits for a start date. */
    private function heldReason(Collection $held, ?LeaveV2Migration $migration, ?string $suggested): string
    {
        $what = match (true) {
            $held->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->isNotEmpty() => 'An up-front base entitlement of '.((float) $held->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->sum('days') + 0).' day(s) is held',
            $migration !== null => 'A Leave V2 migration opening is held; the months it stands for are not mapped',
            default => 'An opening balance of '.((float) $held->sum('days') + 0).' day(s) is held; its origin and months are unknown',
        };

        return $what.'. Set "Monthly Accrual Starts From" — the balance stays as it is and accrual begins from that date.'
            .($suggested ? " (The migration's CSL credits suggest {$suggested}.)" : '');
    }

    public function keyFor(Employee $employee, LeaveYear $year, string $month): string
    {
        return self::KEY_PREFIX.":{$employee->id}:{$year->id}:{$month}";
    }

    /** Why the policy rule cannot drive monthly accrual, or null. */
    private function ruleProblem(?LeavePolicyRule $rule): ?string
    {
        if (! $rule) {
            return 'The policy has no active rule for its Earned Leave type.';
        }
        if ($rule->accrual_method !== LeavePolicyRule::ACCRUAL_MONTHLY) {
            return "Earned Leave accrual is '{$rule->accrual_method}' on the policy; it must be monthly.";
        }
        if (abs((float) $rule->fixed_days - self::ANNUAL_DAYS) > self::EPSILON) {
            return 'Earned Leave annual entitlement is '.($rule->fixed_days ?? 'blank').' on the policy; it must be 12.';
        }
        if ($rule->accrual_amount !== null && abs((float) $rule->accrual_amount - self::MONTHLY_DAYS) > self::EPSILON) {
            return "Earned Leave accrues {$rule->accrual_amount} a month on the policy; it must be 1.";
        }

        return null;
    }

    private function balance(Employee $employee, LeaveType $type, LeaveYear $year): ?LeaveBalance
    {
        return LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere(fn ($w) => $w->whereNull('leave_year_id')->where('year', $year->legacyYear())))
            ->first();
    }

    private function status(Employee $employee): string
    {
        return $employee->status instanceof EmployeeStatus ? $employee->status->value : (string) $employee->status;
    }
}
