<?php

namespace App\Services\Leave;

use App\Enums\EmployeeStatus;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conexus CSL monthly accrual: 1 day for each COMPLETED calendar month of the
 * July–June leave year, at most 12 a year.
 *
 * A month is credited once it has ended — the run on 1 October credits
 * September, effective 30 September — and always in the leave year the month
 * belongs to (June's day is the finishing year's, never the next one's).
 *
 * The current-year CSL credit already on the ledger (the base entitlement the
 * HR register reconciliation posted, e.g. 2) stands for the employee's first
 * eligible months of the year — for the 2026/27 snapshot, July and August.
 * Those months are never credited again: accrual starts at the first month
 * the base does not represent, and an accrual found for a month the base
 * already covers is reversed as a duplicate.
 *
 * Only ever appends: a credit (ACCRUAL, source "conexus_csl_accrual", one
 * deterministic idempotency key per employee, year and month) or a reversal.
 * Carry forward, usage, encashment and every other bucket are never touched;
 * the balance is rebuilt from the ledger afterwards.
 *
 * Anything it cannot read unambiguously BLOCKS that employee — nothing is
 * posted for them and the reason is reported — rather than guessing.
 */
class ConexusCslAccrualService
{
    public const SOURCE_TYPE = 'conexus_csl_accrual';

    public const KEY_PREFIX = 'conexus-csl-accrual';

    public const MONTHLY_CREDIT = 1.0;

    public const PASS = 'PASS';

    public const BLOCKED = 'BLOCKED';

    public const FAIL = 'FAIL';

    /** Employment states in which a month can be earned. */
    private const EMPLOYED = ['onboarding', 'probation', 'confirmed', 'active', 'notice_period', 'on-leave'];

    /** States after employment ended: only months completed by the last working day. */
    private const LEFT = ['resigned', 'terminated', 'absconded', 'inactive', 'archived'];

    private const EPSILON = 0.005;

    public function __construct(
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveLedgerBackfillService $backfill,
        private readonly LeaveYearResolver $years,
        private readonly LeaveRuleResolver $rules,
    ) {}

    /** The leave year being earned on $asOf: the year of the last completed day. */
    public function yearFor(CarbonInterface $asOf): LeaveYear
    {
        return $this->years->forDate(Carbon::parse($asOf)->startOfDay()->subDay());
    }

    /**
     * Everyone the accrual has to consider for the year: employed staff, and
     * leavers who were still employed during it (or whose leaving date is
     * unknown and who hold a CSL balance — they are reported, not credited).
     *
     * @return Collection<int, Employee>
     */
    public function employees(LeaveType $csl, LeaveYear $year): Collection
    {
        return Employee::with(['user', 'exitRecord'])
            ->whereIn('status', [...self::EMPLOYED, ...self::LEFT])
            ->orderBy('id')
            ->get()
            ->filter(function (Employee $employee) use ($csl, $year) {
                if (! in_array($this->status($employee), self::LEFT, true)) {
                    return true;
                }

                $lastDay = $employee->exitRecord?->last_working_day;

                return $lastDay
                    ? $lastDay->gte($year->starts_on)
                    : $this->rowsFor($employee, $csl, $year)->isNotEmpty();
            })
            ->values();
    }

    /**
     * What this employee has earned and what is missing, without writing.
     *
     * @return array{
     *     employee: Employee, name: string, csl_type_id: int, year: LeaveYear, as_of: string,
     *     status: string, reason: ?string, joining_rule: string,
     *     expected_months: array<int, string>, base: float, base_months: array<int, string>,
     *     accrued_months: array<int, string>, duplicates: array<int, int>, missing_months: array<int, string>,
     *     summary: array<string, mixed>, legacy_annual: float
     * }
     */
    public function plan(Employee $employee, LeaveType $csl, LeaveYear $year, CarbonInterface $asOf): array
    {
        $asOf = Carbon::parse($asOf)->startOfDay();
        $rows = $this->rowsFor($employee, $csl, $year);
        $balance = $rows->first();

        $plan = [
            'employee' => $employee,
            'name' => $employee->user?->name ?? ('Employee #'.$employee->id),
            'csl_type_id' => $csl->id,
            'year' => $year,
            'as_of' => $asOf->toDateString(),
            'status' => self::PASS,
            'reason' => null,
            'joining_rule' => $this->rules->settings($employee, $csl)['joining_month_rule'],
            'expected_months' => [],
            'base' => 0.0,
            'base_months' => [],
            'accrued_months' => [],
            'duplicates' => [],
            'missing_months' => [],
            'summary' => $balance ? $this->calculator->summary($balance) : $this->emptySummary(),
            'legacy_annual' => $this->legacyAnnual($employee, $year),
        ];

        $block = function (string $reason) use (&$plan): array {
            $plan['status'] = self::BLOCKED;
            $plan['reason'] = $reason;

            return $plan;
        };

        // Still on the old 28-day Annual Leave: not reconciled to the Conexus
        // policy yet, so its CSL position is not known — never credit months
        // that the reconciliation may already account for.
        if (abs($plan['legacy_annual']) > self::EPSILON) {
            return $block("Legacy Annual Leave still holds {$plan['legacy_annual']} day(s) in {$year->label} — not yet reconciled. Run leave:conexus-reconcile first; no CSL month is credited before it.");
        }

        [$eligible, $problem] = $this->eligibleMonths($employee, $year, $plan['joining_rule']);
        if ($problem !== null) {
            return $block($problem);
        }

        $plan['expected_months'] = array_values(array_filter(
            $eligible,
            fn (string $month) => $this->monthEnd($month)->lt($asOf),
        ));

        if ($rows->count() > 1) {
            return $block("{$rows->count()} CSL balance rows for {$year->label} (#".$rows->pluck('id')->implode(', #').'); merge them first.');
        }

        if ($balance && ! $balance->isLedgerBacked()) {
            $legacy = round((float) $balance->allocated_days + (float) $balance->used_days + (float) $balance->encashed_days, 2);
            if (abs($legacy) > self::EPSILON) {
                return $block("The {$year->label} CSL balance is not on the ledger yet, so its current-year credit cannot be told from carry forward. Run leave:conexus-reconcile (register staff) or the ledger backfill first.");
            }
        }

        $entries = $balance && $balance->isLedgerBacked() ? $this->activeEntries($balance, $year) : collect();

        $opening = round((float) $entries->where('entry_type', LeaveLedgerEntry::TYPE_OPENING)->sum('days'), 2);
        if (abs($opening) > self::EPSILON) {
            return $block("{$opening} day(s) are an undecomposed opening balance — HR must split them into carry forward and current-year credit first.");
        }

        $base = round((float) $entries->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->sum('days'), 2);
        $plan['base'] = $base;

        if (abs($base - round($base)) > self::EPSILON) {
            return $block("The current-year credit of {$base} day(s) is not a whole number of months.");
        }

        if ((int) round($base) > count($eligible)) {
            return $block("The current-year credit of {$base} day(s) exceeds the ".count($eligible).' month(s) this employee can earn in '.$year->label.'.');
        }

        $plan['base_months'] = array_slice($eligible, 0, (int) round($base));
        $ahead = array_values(array_diff($plan['base_months'], $plan['expected_months']));
        if ($ahead !== []) {
            return $block("The current-year credit of {$base} day(s) already covers month(s) not yet completed (".implode(', ', $ahead).'); nothing is credited — HR decision.');
        }

        $seen = [];
        foreach ($entries->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL) as $entry) {
            if ($entry->source_type !== self::SOURCE_TYPE) {
                return $block('An accrual of '.(float) $entry->days.' day(s) (entry #'.$entry->id.') was not posted by the completed-month accrual; HR must decide which month it stands for.');
            }

            $month = (string) ($entry->meta['month'] ?? '');

            if (in_array($month, $plan['base_months'], true) || isset($seen[$month])) {
                // Already represented by the current-year credit, or a second
                // credit for the same month: a duplicate to reverse.
                $plan['duplicates'][] = $entry->id;

                continue;
            }

            if (! in_array($month, $eligible, true)) {
                return $block("Month {$month} was credited (entry #{$entry->id}) but is not an earned month (joining date, leaving date or joining-month rule). HR must correct it.");
            }

            $seen[$month] = true;
            $plan['accrued_months'][] = $month;
        }

        $plan['missing_months'] = array_values(array_diff($plan['expected_months'], $plan['base_months'], $plan['accrued_months']));

        $covered = count($plan['base_months']) + count($plan['accrued_months']) + count($plan['missing_months']);
        if ($covered > (int) ConexusLeavePolicyService::CSL_ANNUAL_DAYS) {
            return $block("{$covered} months would be credited, above the 12-day current-year limit.");
        }

        return $plan;
    }

    /**
     * Post the plan: reverse duplicates, credit missing months, rebuild,
     * verify — in one transaction. A BLOCKED plan posts nothing.
     *
     * @return array<int, string> the movements posted
     *
     * @throws DomainException when the result does not verify (rolled back)
     */
    public function apply(array $plan, LeaveType $csl, ?User $actor = null): array
    {
        if ($plan['status'] !== self::PASS || ($plan['duplicates'] === [] && $plan['missing_months'] === [])) {
            return [];
        }

        $employee = $plan['employee'];
        $year = $plan['year'];

        return DB::transaction(function () use ($plan, $employee, $year, $csl, $actor) {
            $log = [];
            $balance = $this->rowsFor($employee, $csl, $year)->first() ?? LeaveBalance::create([
                'employee_id' => $employee->id, 'leave_type_id' => $csl->id,
                'year' => $year->legacyYear(), 'leave_year_id' => $year->id,
                'allocated_days' => 0, 'used_days' => 0, 'carried_forward_days' => 0,
                'encashed_days' => 0, 'comp_off_credits' => 0,
            ]);

            if (! $this->backfill->migrateIfSafe($balance, $actor)) {
                throw new DomainException('The CSL balance could not be moved onto the ledger safely.');
            }
            $balance->refresh();

            foreach ($plan['duplicates'] as $entryId) {
                $entry = LeaveLedgerEntry::findOrFail($entryId);
                $this->ledger->reverse($entry, 'Conexus CSL accrual: '.($entry->meta['month'] ?? 'month').' is already covered by the current-year credit', $actor);
                $log[] = 'Reversed duplicate credit for '.($entry->meta['month'] ?? '?').'.';
            }

            foreach ($plan['missing_months'] as $month) {
                $end = $this->monthEnd($month);
                $this->ledger->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, self::MONTHLY_CREDIT, $end, $this->keyFor($employee, $year, $month), [
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => $employee->id,
                    'reason' => 'CSL earned for '.$end->format('F Y').' (1 day per completed month)',
                    'meta' => ['month' => $month, 'rule' => '1 day per completed month, 12 a year'],
                    'actor' => $actor,
                ]);
                $log[] = "Credited 1 day for {$month} (effective {$end->toDateString()}).";
            }

            $this->ledger->rebuild($balance);

            $expected = count($plan['base_months']) + count($plan['accrued_months']) + count($plan['missing_months']);
            $s = $this->calculator->summary($balance->fresh());
            if (abs(round($s['base'] + $s['accrued'], 2) - $expected) > self::EPSILON) {
                throw new DomainException("Verification failed: current-year credit is {$s['base']} + {$s['accrued']}, expected {$expected}.");
            }

            return $log;
        });
    }

    /**
     * Plan and (optionally) apply for everyone, then report the result.
     *
     * @param  array<int, string>  $emails  limit to these logins
     * @return array{year: LeaveYear, plans: array<int, array<string, mixed>>, results: array<int, array<string, mixed>>, checks: array<int, array{0: string, 1: bool, 2: string}>}
     */
    public function run(LeaveType $csl, CarbonInterface $asOf, bool $apply, ?User $actor = null, array $emails = []): array
    {
        $year = $this->yearFor($asOf);
        $employees = $this->employees($csl, $year)
            ->when($emails !== [], fn (Collection $c) => $c->filter(
                fn (Employee $e) => in_array(strtolower((string) $e->user?->email), array_map('strtolower', $emails), true)
            ));

        $plans = [];
        $results = [];

        foreach ($employees as $employee) {
            $plan = $this->plan($employee, $csl, $year, $asOf);
            $note = $plan['reason'];

            if ($apply && $plan['status'] === self::PASS) {
                try {
                    $log = $this->apply($plan, $csl, $actor);
                    $note = $log === [] ? 'Up to date.' : implode(' ', $log);
                } catch (Throwable $e) {
                    $plan['status'] = self::BLOCKED;
                    $plan['reason'] = $note = $e->getMessage();
                }
            }

            $plans[] = $plan;
            $results[] = $this->result($employee, $csl, $year, $plan, $note);
        }

        return ['year' => $year, 'plans' => $plans, 'results' => $results, 'checks' => $this->checks($csl, $year)];
    }

    /**
     * The verified position of one employee after a run.
     *
     * @return array<string, mixed>
     */
    public function result(Employee $employee, LeaveType $csl, LeaveYear $year, array $plan, ?string $note = null): array
    {
        $balance = $this->rowsFor($employee, $csl, $year)->first();
        $s = $balance ? $this->calculator->summary($balance->fresh()) : $this->emptySummary();
        $current = round($s['base'] + $s['accrued'], 2);
        $expected = count($plan['expected_months']);
        $legacy = $this->legacyAnnual($employee, $year);

        $failure = match (true) {
            $plan['status'] !== self::PASS => $plan['reason'],
            abs($current - $expected) > self::EPSILON => "Current-year credit {$current} ≠ {$expected} completed month(s).",
            abs($legacy) > self::EPSILON => "Legacy Annual Leave still holds {$legacy} day(s).",
            default => null,
        };

        return [
            'name' => $plan['name'],
            'employee_id' => $employee->id,
            'current_year' => $current,
            'carry' => $s['carry_forward'],
            'used' => $s['used'],
            'encashed' => $s['encashed'],
            'pending' => $s['pending'],
            'approved' => $s['approved_available'],
            'available' => $s['available_to_request'],
            'expected_months' => $expected,
            'legacy_annual' => $legacy,
            'status' => $failure === null ? self::PASS : self::FAIL,
            'note' => $failure ?? $note,
        ];
    }

    /**
     * Policy-wide checks a production run must pass.
     *
     * @return array<int, array{0: string, 1: bool, 2: string}> [check, passed, detail]
     */
    public function checks(LeaveType $csl, LeaveYear $year): array
    {
        $cslNamed = LeaveType::query()
            ->whereIn(DB::raw('LOWER(TRIM(name))'), ['casual / sick leave', 'paid leave', 'casual leave', 'sick leave'])
            ->orWhere('code', ConexusLeavePolicyService::CSL_CODE)
            ->pluck('name', 'id');

        $duplicateMonths = LeaveLedgerEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('leave_year_id', $year->id)
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversedBy')
            ->get(['employee_id', 'meta'])
            ->groupBy(fn (LeaveLedgerEntry $e) => $e->employee_id.'|'.($e->meta['month'] ?? ''))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->count();

        $mdl = DecemberMandatoryDay::forLeaveYear($year)->count();

        $compOff = app(ConexusLeavePolicyService::class)->compOffType();
        $compOffGranted = $compOff
            ? (float) LeaveLedgerEntry::where('leave_type_id', $compOff->id)->where('leave_year_id', $year->id)
                ->whereIn('entry_type', [LeaveLedgerEntry::TYPE_BASE, LeaveLedgerEntry::TYPE_ACCRUAL])
                ->whereNull('reverses_entry_id')->whereDoesntHave('reversedBy')->sum('days')
            : 0.0;
        $policy = app(ConexusLeavePolicyService::class)->policy();
        $compOffRule = $compOff && $policy
            ? LeavePolicyRule::where('leave_policy_id', $policy->id)->where('leave_type_id', $compOff->id)->get()
            : collect();

        $annual = app(ConexusLeavePolicyService::class)->legacyAnnualType();

        return [
            ['One CSL type, no duplicate', $cslNamed->count() === 1 && $cslNamed->keys()->first() === $csl->id,
                $cslNamed->map(fn ($name, $id) => "#{$id} {$name}")->implode(', ')],
            ['No duplicate month credits', $duplicateMonths === 0, "{$duplicateMonths} employee-month(s) credited twice"],
            ['MDL is six dates, never a balance', $mdl === ConexusLeavePolicyService::MDL_DAYS, "{$mdl} date(s) configured for {$year->label}"],
            ['Comp Off is earned only', $compOffGranted < self::EPSILON && $compOffRule->every(fn ($r) => $r->entitlement_method === LeavePolicyRule::ENTITLEMENT_NONE),
                $compOff ? "{$compOffGranted} day(s) granted by entitlement or accrual" : 'no Comp Off type yet (created on first earned credit)'],
            ['Annual Leave retired for new use', $annual === null || $annual->trashed(), $annual ? "type #{$annual->id}".($annual->trashed() ? ' retired' : ' still active') : 'no Annual Leave type'],
        ];
    }

    // ── Months ──────────────────────────────────────────────────────────────

    /**
     * Months of the year the employee can earn ('YYYY-MM', in order), or a
     * reason the months cannot be known.
     *
     * @return array{0: array<int, string>, 1: ?string}
     */
    public function eligibleMonths(Employee $employee, LeaveYear $year, string $joiningRule): array
    {
        if (! $employee->joining_date) {
            return [[], 'No joining date — the months earned cannot be known. HR must record it.'];
        }

        $joined = Carbon::parse($employee->joining_date)->startOfDay();
        $lastDay = $employee->exitRecord?->last_working_day ? Carbon::parse($employee->exitRecord->last_working_day)->startOfDay() : null;

        if ($lastDay === null && in_array($this->status($employee), self::LEFT, true)) {
            return [[], 'Status is '.$this->status($employee).' but no last working day is recorded — HR must record it.'];
        }

        $months = [];
        foreach ($this->months($year) as $month) {
            $start = Carbon::parse($month.'-01')->startOfDay();
            $end = $this->monthEnd($month);

            $joinedInTime = match (true) {
                $joined->lt($start) => true,
                $joined->gt($end) => false,
                default => match ($joiningRule) {
                    LeavePolicyRule::JOINING_FULL => true,
                    LeavePolicyRule::JOINING_HALF_MONTH => $joined->day <= 15,
                    default => $joined->day === 1,
                },
            };

            // A completed month needs the whole month worked to its end.
            if ($joinedInTime && ($lastDay === null || $lastDay->gte($end))) {
                $months[] = $month;
            }
        }

        return [$months, null];
    }

    /** @return array<int, string> the twelve 'YYYY-MM' of the year */
    public function months(LeaveYear $year): array
    {
        $months = [];
        for ($cursor = Carbon::parse($year->starts_on)->startOfMonth(); $cursor->lte($year->ends_on); $cursor = $cursor->addMonth()) {
            $months[] = $cursor->format('Y-m');
        }

        return $months;
    }

    public function monthEnd(string $month): Carbon
    {
        return Carbon::parse($month.'-01')->endOfMonth()->startOfDay();
    }

    /** The deterministic key; versioned only when an earlier credit for the month was reversed. */
    public function keyFor(Employee $employee, LeaveYear $year, string $month): string
    {
        $key = self::KEY_PREFIX.":{$employee->id}:{$year->id}:{$month}";
        $earlier = LeaveLedgerEntry::where('idempotency_key', $key)->orWhere('idempotency_key', 'like', $key.':v%')->count();

        return $earlier === 0 ? $key : $key.':v'.($earlier + 1);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** @return Collection<int, LeaveBalance> */
    private function rowsFor(Employee $employee, LeaveType $type, LeaveYear $year): Collection
    {
        return LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->get();
    }

    /** @return Collection<int, LeaveLedgerEntry> live entries: not reversed, not reversals */
    private function activeEntries(LeaveBalance $balance, LeaveYear $year): Collection
    {
        return LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversedBy')
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();
    }

    /** The retired 28-day Annual Leave still counted for the year (must be 0). */
    private function legacyAnnual(Employee $employee, LeaveYear $year): float
    {
        $annual = app(ConexusLeavePolicyService::class)->legacyAnnualType();

        return $annual
            ? round($this->rowsFor($employee, $annual, $year)->sum(fn (LeaveBalance $b) => $this->calculator->summary($b->fresh())['approved_available']), 2)
            : 0.0;
    }

    private function status(Employee $employee): string
    {
        return $employee->status instanceof EmployeeStatus ? $employee->status->value : (string) $employee->status;
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'base' => 0.0, 'carry_forward' => 0.0, 'accrued' => 0.0, 'add_on' => 0.0,
            'adjustment_credit' => 0.0, 'adjustment_debit' => 0.0, 'opening' => 0.0,
            'expired' => 0.0, 'used' => 0.0, 'encashed' => 0.0, 'credits' => 0.0,
            'approved_available' => 0.0, 'pending' => 0.0, 'available_to_request' => 0.0,
            'ledger_backed' => false, 'ledger_status' => null,
        ];
    }
}
