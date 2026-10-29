<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\LeaveV2Migration;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Security\PeopleScope;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Leave V2 historical reconciliation: the HR register is the authoritative
 * leave snapshot up to the cutoff (30 Sep 2026); everything the portal did
 * after it is kept. Per employee:
 *
 *   HR baseline   = HR carry forward + HR current-year earned − HR used
 *   Expected now  = HR baseline
 *                   − paid leave used after the cutoff (net of cancellations)
 *                   + HR adjustments after the cutoff
 *                   + monthly accrual for months the HR credit does not cover
 *                   (− any expiry / encashment after the cutoff)
 *   Existing now  = today's Earned Leave total available (EarnedLeaveBreakdown)
 *
 * Every ledger entry is read by classify(): only genuine business after the
 * cutoff counts (leave taken, HR adjustments, monthly Earned Leave accrual,
 * expiry). Leave V2 mechanics (the migration opening, its reversal, the
 * carry-forward / current-year split), the register's own postings and the
 * CSL bridge (CSL accruals and adjustments after the snapshot, which carried
 * the old CSL to the migration) are listed but not counted. Excluding never
 * hides days: they are still in today's balance, so a genuine entry read as
 * mechanics shows up as a DIFFERENCE, and apply() refuses.
 *
 * Portal activity is read from the Earned Leave pool AND the CSL ledger it was
 * migrated from: a day taken on CSL in October, before the migration, is
 * inside the Earned opening — the opening itself is never used as the
 * baseline, so that day is counted once, here. Activity dated on or before
 * the cutoff is the register's (never counted twice); the register's own
 * postings (conexus_register) and the opening / base / carry-forward credits
 * the baseline replaces are ignored. Pending requests are shown beside it and
 * never reduce anything. Comp Off, Unpaid and Special Leave are not read.
 *
 * plan() only reads. apply() restates ONE employee whose row is a MATCH so
 * the Earned Leave pool shows the HR breakdown instead of the migrated
 * opening — the total available is exactly the same before and after:
 *
 *   − today's baseline lines       each reversed, never edited: the migration
 *                                  opening, or the carry-forward / current-year
 *                                  split leave:earned-opening-sources made of it
 *   + Carry Forward     (HR)       hr-history:{employee}:{year}:carry_forward
 *   + Current-year earned (HR)     hr-history:{employee}:{year}:current_year
 *   − Leave used to the cutoff (HR) hr-history:{employee}:{year}:historical_used
 *   ⇄ a portal day after the cutoff still on CSL moves to Earned Leave: the
 *     CSL usage is reversed and re-posted on Earned Leave with the same
 *     leave-request link, so a later cancellation returns it to Earned Leave
 *     (hr-history:{employee}:{year}:moved:{csl entry})
 *
 * Monthly accruals, HR adjustments and usage already on Earned Leave are not
 * touched; nor are leave requests, pending requests, Monthly Accrual Starts
 * From, Comp Off, Unpaid or Special Leave. Re-running posts nothing.
 */
class LeaveHrHistoryReconciliationService
{
    public const MATCH = 'MATCH';

    public const DIFFERENCE = 'DIFFERENCE';

    public const MISSING = 'MISSING HR HISTORY';

    public const REVIEW = 'NEEDS REVIEW';

    /** Already restated to the HR register, and still agreeing with it. */
    public const NO_CHANGE = 'NO CHANGE';

    /** apply() result. */
    public const RECONCILED = 'RECONCILED';

    public const SOURCE_TYPE = 'hr_history_reconciliation';

    public const KEY_PREFIX = 'hr-history';

    /** HR's current-year credit is earned at 1 day a month from the start of the leave year. */
    private const MONTHLY_DAYS = 1.0;

    private const EPSILON = 0.005;

    /** Credits the HR baseline stands in for: never portal activity. */
    private const BASELINE_TYPES = [LeaveLedgerEntry::TYPE_OPENING, LeaveLedgerEntry::TYPE_BASE, LeaveLedgerEntry::TYPE_CARRY_FORWARD];

    private const ADJUSTMENT_TYPES = [LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT, LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT, LeaveLedgerEntry::TYPE_ADD_ON];

    /** Usage linked to a real record: it can move ledgers with its link. */
    private const LINKED_USAGE = ['leave_request', 'leave_regularisation'];

    /** A cancellation that returned days as a credit (LeaveMovementService). */
    private const USAGE_RETURN = 'leave_request_return';

    /** Leave V2 bookkeeping: the migration opening and the opening-source split. */
    private const MECHANIC_SOURCES = ['leave_v2_migration', EarnedOpeningSourceService::SOURCE_TYPE];

    /** HR add-on kinds that restate history, which the register now states. */
    private const HISTORY_KINDS = ['opening_balance_correction', 'historical_balance'];

    // ── How each ledger entry is read (a reversal is read as the entry it reverses) ──

    /** Counted: leave taken (or returned) after the cutoff. */
    public const C_USAGE = 'portal usage';

    /** Counted: an HR add / deduct / correction on Earned Leave after the cutoff. */
    public const C_ADJUSTMENT = 'HR adjustment';

    /** Counted: a genuine monthly Earned Leave accrual. */
    public const C_ACCRUAL = 'monthly accrual';

    /** Counted: expiry or encashment after the cutoff. */
    public const C_OTHER = 'expiry / encashment';

    /** Excluded: the register's own postings, or a restatement already made here. */
    public const C_REGISTER = 'HR register';

    /** Excluded: the Leave V2 migration opening, its reversal, the carry-forward / current-year split. */
    public const C_MECHANICS = 'migration mechanics';

    /** Excluded: CSL accruals and CSL adjustments after the snapshot — the bridge from the old CSL to the migration. */
    public const C_BRIDGE = 'CSL bridge';

    /** Excluded: an HR credit HR marked as an opening / historical correction. */
    public const C_HISTORY_FIX = 'history correction';

    /** Excluded: CSL movement inside the HR period — already in the register. */
    public const C_HR_PERIOD = 'HR period';

    /** NEEDS REVIEW: an Earned Leave movement dated inside the HR period. */
    public const C_EARLY = 'dated in HR period';

    /** NEEDS REVIEW: a movement this reconciliation cannot read. */
    public const C_UNSUPPORTED = 'unsupported';

    private const COUNTED = [self::C_USAGE, self::C_ADJUSTMENT, self::C_ACCRUAL, self::C_OTHER];

    public function __construct(
        private readonly LeaveRegisterReconciliationService $register,
        private readonly LeaveBalanceSource $sources,
        private readonly EarnedLeaveBreakdown $breakdown,
        private readonly LeaveV2MigrationService $migration,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveMovementService $movements,
    ) {}

    /**
     * Every register row (matched or not) and every working Earned Leave
     * employee the register does not list.
     *
     * @param  array<string, mixed>  $register  LeaveRegisterReconciliationService::loadRegister()
     * @param  array<int, string>  $codes  employee codes (employee_id or numeric employee_code); empty = everyone
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(array $register, LeaveYear $year, Carbon $cutoff, array $codes = []): Collection
    {
        $wanted = fn (?Employee $e) => $codes === [] || ($e !== null && (in_array((string) $e->employee_id, $codes, true) || in_array((string) $e->employee_code, $codes, true)));
        $rows = collect();
        $seen = [];

        foreach ($register['employees'] as $hr) {
            $employee = $this->register->findEmployee($hr);
            if (! $wanted($employee)) {
                continue;
            }
            if ($employee !== null && isset($seen[$employee->id])) {
                $rows->push($this->planFor($employee, $hr, $year, $cutoff, 'The register lists this employee more than once.'));

                continue;
            }
            if ($employee !== null) {
                $seen[$employee->id] = true;
            }
            $rows->push($employee ? $this->planFor($employee, $hr, $year, $cutoff) : $this->unmatched($hr));
        }
        $source = (string) ($register['source'] ?? 'HR register');

        Employee::with('user')->whereIn('status', PeopleScope::WORKING_STATUSES)->whereNotIn('id', array_keys($seen))->orderBy('employee_id')->get()
            ->filter(fn (Employee $e) => $wanted($e) && $this->sources->earnedPool($e, $this->onDate($year)) !== null)
            ->each(fn (Employee $e) => $rows->push($this->planFor($e, null, $year, $cutoff)));

        return $rows->map(fn (array $row) => $row + ['register_source' => $source])->values();
    }

    /**
     * @param  array<string, mixed>|null  $hr  the register row, null when the register does not list the employee
     * @return array<string, mixed>
     */
    public function planFor(Employee $employee, ?array $hr, LeaveYear $year, Carbon $cutoff, ?string $problem = null): array
    {
        $pool = $this->sources->earnedPool($employee, $this->onDate($year));
        $breakdown = $pool ? $this->breakdown->for($employee, $year, $pool) : null;

        $row = [
            'employee_id' => $employee->id,
            'employee' => $employee->user?->name ?? $hr['name'] ?? 'Employee #'.$employee->id,
            'code' => $employee->employee_id,
            'year_id' => $year->id,
            'cutoff' => $cutoff->toDateString(),
            'earned_type_id' => $pool?->id,
            'register_row' => $hr,
            'hr_carry_forward' => $hr ? round((float) $hr['carry'], 2) : null,
            'hr_current_year' => $hr ? round((float) $hr['credit'], 2) : null,
            'hr_used' => $hr ? round((float) $hr['used'], 2) : null,
            'hr_baseline' => $hr ? round((float) $hr['carry'] + (float) $hr['credit'] - (float) $hr['used'], 2) : null,
            'used_after' => 0.0, 'adjustments_after' => 0.0, 'accrual_after' => 0.0, 'other_after' => 0.0, 'excluded_after' => 0.0,
            'entries' => [],
            'pending' => $breakdown['pending'] ?? 0.0,
            'expected' => null,
            'existing' => $breakdown['total_available'] ?? null,
            'difference' => null,
            'existing_lines' => $breakdown ? $this->linesOf($breakdown) : null,
            'apply_blocker' => null,
            'status' => self::REVIEW, 'reason' => '',
        ];
        // By reference: a stop reports the figures gathered so far.
        $stop = function (string $status, string $reason) use (&$row): array {
            return ['status' => $status, 'reason' => $reason] + $row;
        };

        if ($pool === null) {
            return $stop(self::REVIEW, 'The employee\'s policy has no Earned Leave pool.');
        }

        // Portal activity: the Earned Leave pool, and the CSL it was migrated from.
        $csl = $this->migration->sourceType();
        $activity = $this->activity($employee, $year, $cutoff, $pool, $csl);
        $covered = $hr ? $this->coveredMonths($year, (float) $hr['credit']) : [];
        $row = [
            'used_after' => $activity['used_after'],
            'adjustments_after' => $activity['adjustments_after'],
            'accrual_after' => round((float) collect($activity['accruals'])->reject(fn ($days, $month) => in_array($month, $covered ?? [], true))->sum(), 2),
            'other_after' => $activity['other_after'],
            'excluded_after' => $activity['excluded_after'],
            'entries' => $activity['entries'],
        ] + $row;

        if ($hr === null) {
            return $stop(self::MISSING, 'Not in the HR register — there is no historical snapshot to reconcile against.');
        }
        if ($problem !== null) {
            return $stop(self::REVIEW, $problem);
        }

        $row['expected'] = round($row['hr_baseline'] - $row['used_after'] + $row['adjustments_after'] + $row['accrual_after'] + $row['other_after'], 2);
        $row['difference'] = $row['existing'] === null ? null : round($row['existing'] - $row['expected'], 2);

        $notes = $activity['notes'];
        if (abs((float) $hr['encashed']) > self::EPSILON) {
            $notes[] = 'the register records '.((float) $hr['encashed'] + 0).' day(s) encashed, which the baseline formula does not cover';
        }
        if ($covered === null) {
            $notes[] = 'the HR current-year credit ('.((float) $hr['credit'] + 0).') is not a whole number of months';
        }
        $double = array_values(array_intersect(array_keys($activity['accruals']), $covered ?? []));
        if ($double !== []) {
            $notes[] = 'monthly accrual was posted for '.implode(', ', $double).', which the HR current-year credit already covers';
        }
        if (! ($breakdown['has_balance'] ?? false)) {
            $notes[] = 'no Earned Leave balance exists for '.$year->label;
        }
        if ($notes !== []) {
            return $stop(self::REVIEW, ucfirst(implode('; ', $notes)).'.');
        }

        $gap = $this->accrualGap($covered, $breakdown['starts_from'] ?? null);
        $summary = sprintf('HR %s + %s − %s = %s; after %s: −%s used, %s HR, +%s accrual.',
            $this->n($row['hr_carry_forward']), $this->n($row['hr_current_year']), $this->n($row['hr_used']), $this->n($row['hr_baseline']),
            $cutoff->format('d M'), $this->n($row['used_after']), $this->signed($row['adjustments_after']), $this->n($row['accrual_after']))
            .(abs($row['excluded_after']) > self::EPSILON ? ' Not counted (CSL bridge / migration corrections): '.$this->signed($row['excluded_after']).'.' : '')
            .($gap ? ' Note: '.$gap : '');

        if (abs((float) $row['difference']) >= self::EPSILON) {
            return $stop(self::DIFFERENCE, 'Portal shows '.$this->n($row['existing']).', expected '.$this->n($row['expected']).' ('.$this->signed($row['difference']).'). '.$summary);
        }
        if ($this->applied($employee, $year)) {
            return $stop(self::NO_CHANGE, 'Already restated to the HR register; still agrees. '.$summary);
        }

        $row['apply_blocker'] = $this->shapeProblem($employee, $year, $pool, $activity['classified']);

        return $stop(self::MATCH, $summary.($row['apply_blocker'] ? ' Apply would refuse: '.$row['apply_blocker'] : ''));
    }

    /**
     * Restate ONE employee's Earned Leave to the HR register — only a MATCH
     * with a supported ledger shape, re-judged under a lock; the total
     * available must be identical before and after, or nothing is kept.
     *
     * @param  array<string, mixed>  $planned  a row from planFor()
     * @return array<string, mixed>
     */
    public function apply(array $planned, User $actor, string $reason): array
    {
        if (trim($reason) === '') {
            throw new DomainException('A reason is required to restate a balance to the HR register.');
        }

        return DB::transaction(function () use ($planned, $actor, $reason) {
            $employee = Employee::with('user')->findOrFail($planned['employee_id']);
            if ((int) $employee->user_id === (int) $actor->id) {
                throw new DomainException('You cannot restate your own leave balance.');
            }
            $year = LeaveYear::findOrFail($planned['year_id']);
            $cutoff = Carbon::parse($planned['cutoff']);
            $pool = LeaveType::findOrFail($planned['earned_type_id']);
            $csl = $this->migration->sourceType();

            $earnedBalance = $this->movements->balanceFor($employee->id, $pool->id, Carbon::parse($year->starts_on));
            LeaveBalance::whereKey($earnedBalance->id)->lockForUpdate()->first();
            $cslBalance = $csl ? $this->balanceOf($employee, $csl, $year) : null;
            if ($cslBalance) {
                LeaveBalance::whereKey($cslBalance->id)->lockForUpdate()->first();
            }

            $row = $this->planFor($employee, $planned['register_row'], $year, $cutoff);
            if ($row['status'] !== self::MATCH || abs((float) $row['difference']) >= self::EPSILON || $row['apply_blocker'] !== null) {
                return $row;
            }

            $before = $this->breakdown->for($employee, $year, $pool);
            $migration = LeaveV2Migration::where('employee_id', $employee->id)->where('earned_leave_type_id', $pool->id)->where('leave_year_id', $year->id)->first();
            $key = fn (string $part) => self::KEY_PREFIX.":{$employee->id}:{$year->id}:{$part}";
            $why = 'HR register to '.$cutoff->format('d M Y').': '.$reason;
            $meta = ['cutoff' => $cutoff->toDateString(), 'register_source' => $planned['register_source'] ?? 'HR register', 'actor' => 'user:'.$actor->id,
                'hr' => ['carry' => $row['hr_carry_forward'], 'credit' => $row['hr_current_year'], 'used' => $row['hr_used']]];
            $common = ['source_type' => self::SOURCE_TYPE, 'source_id' => $migration?->id, 'actor' => $actor];
            $posted = [];

            // 1. Today's baseline lines — the migration opening, or the carry-forward /
            //    current-year split it was reclassified into — give way to the HR
            //    breakdown: each is reversed, never edited.
            foreach ($this->live($employee, $pool, $year)->whereIn('entry_type', self::BASELINE_TYPES) as $line) {
                $posted['baseline_reversals'][] = $this->ledger->reverse($line, 'Replaced by the HR register breakdown — '.$why, $actor, ['meta' => $meta])->id;
            }
            // 2. The HR snapshot, as its own lines.
            $start = Carbon::parse($year->starts_on);
            foreach ([['carry_forward', LeaveLedgerEntry::TYPE_CARRY_FORWARD, $row['hr_carry_forward'], 'Carry Forward (HR register)'],
                ['current_year', LeaveLedgerEntry::TYPE_BASE, $row['hr_current_year'], $year->label.' earned to '.$cutoff->format('d M Y').' (HR register)']] as [$part, $type, $days, $label]) {
                if ($days > self::EPSILON) {
                    $posted[$part] = $this->ledger->credit($earnedBalance->fresh(), $type, $days, $start, $key($part), $common + ['reason' => $label, 'meta' => $meta + ['part' => $part]])->id;
                }
            }
            if ($row['hr_used'] > self::EPSILON) {
                $posted['historical_used'] = $this->ledger->debit($earnedBalance->fresh(), LeaveLedgerEntry::TYPE_USAGE, $row['hr_used'], $cutoff, $key('historical_used'),
                    $common + ['reason' => 'Leave used to '.$cutoff->format('d M Y').' (HR register)', 'meta' => $meta + ['part' => 'historical_used', 'historical' => true]])->id;
            }
            // 3. Portal days after the cutoff still on CSL move to Earned Leave, keeping their link.
            foreach ($this->cslMoves($employee, $year, $pool, $csl, $cutoff) as $entry) {
                $this->ledger->reverse($entry, 'Moved to Earned Leave — '.$why, $actor, ['meta' => $meta]);
                $posted['moved'][] = $this->ledger->debit($earnedBalance->fresh(), LeaveLedgerEntry::TYPE_USAGE, -(float) $entry->days, Carbon::parse($entry->effective_date),
                    $key('moved:'.$entry->id), ['source_type' => $entry->source_type, 'source_id' => $entry->source_id, 'actor' => $actor,
                        'reason' => $entry->reason, 'meta' => ($entry->meta ?? []) + ['moved_from_entry' => $entry->id, 'hr_history' => true]])->id;
            }
            $this->ledger->rebuild($earnedBalance->fresh());
            if ($cslBalance) {
                $this->ledger->rebuild($cslBalance->fresh());
            }

            $after = $this->breakdown->for($employee, $year, $pool);
            if (abs($after['total_available'] - $before['total_available']) >= self::EPSILON || ! $after['consistent']
                || abs($after['total_available'] - (float) $row['expected']) >= self::EPSILON || abs($after['opening']) >= self::EPSILON) {
                throw new RuntimeException("Restating {$employee->employee_id} would change the balance ({$before['total_available']} → {$after['total_available']}, expected {$row['expected']}); nothing was kept.");
            }

            app(AuditService::class)->event('LEAVE_HR_HISTORY_RECONCILED', AuditService::LEAVE, $earnedBalance->fresh(),
                old: ['breakdown' => $this->linesOf($before)],
                new: ['breakdown' => $this->linesOf($after), 'entries' => $posted, 'cutoff' => $cutoff->toDateString(), 'register' => $meta['hr'],
                    'register_source' => $meta['register_source'], 'actor' => $actor->email],
                reason: $why, subjectEmployeeId: $employee->id, actor: $actor);

            return ['status' => self::RECONCILED, 'reason' => 'Restated to the HR register; available unchanged at '.$this->n($after['total_available']).'.',
                'before_lines' => $this->linesOf($before), 'after_lines' => $this->linesOf($after), 'entries' => $posted] + $row;
        });
    }

    /** The employee was already restated to the HR register this year. */
    public function applied(Employee $employee, LeaveYear $year): bool
    {
        return LeaveLedgerEntry::where('idempotency_key', 'like', self::KEY_PREFIX.":{$employee->id}:{$year->id}:%")->exists();
    }

    /**
     * Why apply() cannot restate this employee safely, or null. The preview
     * has already refused anything unreadable (NEEDS REVIEW); what is left
     * to check is what apply() must act on: the Earned Leave pool came from
     * the migration, its baseline lines are migration mechanics, and the CSL
     * leave taken after the cutoff can move with its link.
     *
     * @param  Collection<int, array{entry: LeaveLedgerEntry, class: string}>  $classified
     */
    private function shapeProblem(Employee $employee, LeaveYear $year, LeaveType $pool, Collection $classified): ?string
    {
        if (! LeaveV2Migration::where('employee_id', $employee->id)->where('earned_leave_type_id', $pool->id)->where('leave_year_id', $year->id)->exists()) {
            return 'no Leave V2 migration record — the Earned Leave history was built another way.';
        }

        $live = fn (array $c) => $c['entry']->reverses_entry_id === null && ! $classified->contains(fn ($o) => $o['entry']->reverses_entry_id === $c['entry']->id);
        $baseline = $classified->filter(fn ($c) => $c['entry']->leave_type_id === $pool->id && in_array($c['entry']->entry_type, self::BASELINE_TYPES, true) && $live($c));
        if ($odd = $baseline->reject(fn ($c) => $c['class'] === self::C_MECHANICS)->pluck('entry')->all()) {
            return 'Earned Leave baseline lines not from the migration (#'.collect($odd)->pluck('id')->implode(', #').').';
        }
        $unmovable = $classified->filter(fn ($c) => $c['entry']->leave_type_id !== $pool->id && $c['class'] === self::C_USAGE && $live($c)
            && $c['entry']->entry_type !== LeaveLedgerEntry::TYPE_USAGE);
        if ($unmovable->isNotEmpty()) {
            return 'CSL holds returned days after the snapshot (#'.$unmovable->pluck('entry.id')->implode(', #').') — they cannot be moved safely.';
        }

        return null;
    }

    /** @return Collection<int, LeaveLedgerEntry> live CSL usage after the cutoff, to move to Earned Leave */
    private function cslMoves(Employee $employee, LeaveYear $year, LeaveType $pool, ?LeaveType $csl, Carbon $cutoff): Collection
    {
        return $csl === null ? collect() : $this->live($employee, $csl, $year)
            ->filter(fn (LeaveLedgerEntry $e) => $e->entry_type === LeaveLedgerEntry::TYPE_USAGE && $this->classify($e, $pool, $cutoff) === self::C_USAGE)
            ->values();
    }

    /** @return Collection<int, LeaveLedgerEntry> entries neither reversed nor reversals */
    private function live(Employee $employee, LeaveType $type, LeaveYear $year): Collection
    {
        $entries = LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('leave_year_id', $year->id)->orderBy('id')->get();
        $reversed = $entries->whereNotNull('reverses_entry_id')->pluck('reverses_entry_id')->flip();

        return $entries->whereNull('reverses_entry_id')->reject(fn (LeaveLedgerEntry $e) => $reversed->has($e->id))->values();
    }

    /**
     * Portal movements of one employee's Earned Leave (and the CSL it was
     * migrated from) in the year, each read by classify(). Only the counted
     * kinds move the expected figure; excluded ones are listed beside it.
     *
     * @return array{used_after: float, adjustments_after: float, other_after: float, excluded_after: float, accruals: array<string, float>,
     *     notes: array<int, string>, classified: Collection<int, array{entry: LeaveLedgerEntry, class: string}>, entries: array<int, array<string, mixed>>}
     */
    private function activity(Employee $employee, LeaveYear $year, Carbon $cutoff, LeaveType $pool, ?LeaveType $csl): array
    {
        $classified = LeaveLedgerEntry::with('reverses')->where('employee_id', $employee->id)
            ->whereIn('leave_type_id', array_values(array_filter([$pool->id, $csl?->id])))
            ->where('leave_year_id', $year->id)->orderBy('id')->get()
            ->map(fn (LeaveLedgerEntry $e) => ['entry' => $e, 'class' => $this->classify($e, $pool, $cutoff)]);
        $sum = fn (array $classes) => round((float) $classified->whereIn('class', $classes)->sum(fn ($c) => (float) $c['entry']->days), 2);
        $ids = fn (string $class) => $classified->where('class', $class)->pluck('entry.id');

        $notes = [];
        if ($ids(self::C_EARLY)->isNotEmpty()) {
            $notes[] = 'Earned Leave holds '.$ids(self::C_EARLY)->count().' movement(s) dated on or before '.$cutoff->format('d M Y').' (#'.$ids(self::C_EARLY)->implode(', #').') — they may already be in the HR register';
        }
        if ($ids(self::C_UNSUPPORTED)->isNotEmpty()) {
            $notes[] = 'movements this reconciliation cannot read (#'.$classified->where('class', self::C_UNSUPPORTED)
                ->map(fn ($c) => $c['entry']->id.' '.str_replace('_', ' ', $c['entry']->entry_type).' '.($c['entry']->source_type ?? 'no source'))->implode(', #').')';
        }

        $accruals = $classified->where('class', self::C_ACCRUAL)
            ->groupBy(fn ($c) => ($c['entry']->reverses ?? $c['entry'])->meta['month'] ?? Carbon::parse(($c['entry']->reverses ?? $c['entry'])->effective_date)->format('Y-m'))
            ->map(fn (Collection $g) => round((float) $g->sum(fn ($c) => (float) $c['entry']->days), 2))
            ->filter(fn (float $days) => abs($days) > self::EPSILON)
            ->sortKeys()->all();

        return [
            'used_after' => -$sum([self::C_USAGE]) + 0.0,
            'adjustments_after' => $sum([self::C_ADJUSTMENT]),
            'other_after' => $sum([self::C_OTHER]),
            'excluded_after' => $sum([self::C_BRIDGE, self::C_HISTORY_FIX]),
            'accruals' => $accruals,
            'notes' => $notes,
            'classified' => $classified,
            'entries' => $classified->map(fn ($c) => [
                'id' => $c['entry']->id, 'ledger' => $c['entry']->leave_type_id === $pool->id ? 'Earned' : 'CSL',
                'type' => $c['entry']->entry_type, 'days' => (float) $c['entry']->days,
                'date' => Carbon::parse(($c['entry']->reverses ?? $c['entry'])->effective_date)->toDateString(),
                'source' => $c['entry']->source_type, 'reverses' => $c['entry']->reverses_entry_id,
                'class' => $c['class'], 'counted' => in_array($c['class'], self::COUNTED, true),
            ])->values()->all(),
        ];
    }

    /**
     * What one ledger entry is, for the reconciliation. A reversal is read as
     * the entry it reverses (its source and date), so a cancellation nets to
     * zero in the same place as the day it cancels.
     *
     * Earned Leave: monthly accruals, HR adjustments, leave taken and
     * expiry after the cutoff are business; the migration opening and its
     * carry-forward / current-year split are mechanics. CSL: only leave
     * taken after the cutoff is business (it sits inside the migrated
     * opening); its accruals and adjustments after the snapshot bridged the
     * old CSL to the migration, and the register now states that history.
     * Anything else is unsupported — NEEDS REVIEW, never guessed.
     */
    public function classify(LeaveLedgerEntry $entry, LeaveType $pool, Carbon $cutoff): string
    {
        $original = $entry->reverses ?? $entry;
        $source = (string) $original->source_type;
        $type = $entry->entry_type;
        $after = Carbon::parse($original->effective_date)->gt($cutoff);
        $onPool = $entry->leave_type_id === $pool->id;
        $linked = in_array($source, self::LINKED_USAGE, true);
        $usage = ($type === LeaveLedgerEntry::TYPE_USAGE && $linked)
            || ($type === LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT && $source === self::USAGE_RETURN);

        return match (true) {
            in_array($source, [LeaveRegisterReconciliationService::SOURCE_TYPE, self::SOURCE_TYPE], true) => self::C_REGISTER,
            in_array($source, self::MECHANIC_SOURCES, true) => self::C_MECHANICS,
            // CSL
            ! $onPool && $type === LeaveLedgerEntry::TYPE_ACCRUAL => self::C_BRIDGE,
            ! $onPool && ! $after => self::C_HR_PERIOD,
            ! $onPool && $usage => self::C_USAGE,
            ! $onPool && in_array($type, self::ADJUSTMENT_TYPES, true) => self::C_BRIDGE,
            ! $onPool => self::C_UNSUPPORTED,
            // Earned Leave
            $type === LeaveLedgerEntry::TYPE_ACCRUAL => str_starts_with((string) $original->idempotency_key, EarnedLeaveAccrualService::KEY_PREFIX.':') ? self::C_ACCRUAL : self::C_UNSUPPORTED,
            in_array($type, self::BASELINE_TYPES, true) => self::C_UNSUPPORTED,
            ! $after => self::C_EARLY,
            $usage => self::C_USAGE,
            in_array($type, self::ADJUSTMENT_TYPES, true) => in_array($original->meta['add_on_type'] ?? null, self::HISTORY_KINDS, true) ? self::C_HISTORY_FIX : self::C_ADJUSTMENT,
            in_array($type, [LeaveLedgerEntry::TYPE_EXPIRY, LeaveLedgerEntry::TYPE_ENCASHMENT], true) => self::C_OTHER,
            default => self::C_UNSUPPORTED,
        };
    }

    /**
     * The months HR's current-year credit stands for (2 → July and August),
     * or null when it is not a whole number of months.
     *
     * @return array<int, string>|null
     */
    private function coveredMonths(LeaveYear $year, float $credit): ?array
    {
        $months = (int) round($credit / self::MONTHLY_DAYS);
        if (abs($credit - $months * self::MONTHLY_DAYS) > self::EPSILON || $months < 0 || $months > 12) {
            return null;
        }

        return collect(range(0, $months - 1))->filter(fn ($i) => $months > 0)
            ->map(fn (int $i) => Carbon::parse($year->starts_on)->addMonthsNoOverflow($i)->format('Y-m'))->values()->all();
    }

    /** A month earned by neither the HR credit nor monthly accrual. */
    private function accrualGap(?array $covered, ?string $startsFrom): ?string
    {
        if ($covered === null || $startsFrom === null) {
            return null;
        }
        $next = $covered === [] ? null : Carbon::parse(end($covered).'-01')->addMonthNoOverflow()->format('Y-m');
        $start = Carbon::parse($startsFrom)->format('Y-m');

        return $next !== null && $start > $next
            ? 'the HR credit covers to '.Carbon::parse(end($covered).'-01')->format('M Y').' but Monthly Accrual Starts From is '.Carbon::parse($startsFrom)->format('M Y')
                .' — '.Carbon::parse($next.'-01')->format('F Y').' is earned by neither.'
            : null;
    }

    /**
     * The source lines of a breakdown, for before/after reports and the audit.
     *
     * @param  array<string, mixed>  $breakdown
     * @return array<string, mixed>
     */
    private function linesOf(array $breakdown): array
    {
        return array_intersect_key($breakdown, array_flip(['carry_forward', 'current_year_accrued', 'opening', 'hr_adjustment',
            'used', 'historical_used', 'portal_used', 'expired', 'total_available', 'pending']));
    }

    private function balanceOf(Employee $employee, LeaveType $type, LeaveYear $year): ?LeaveBalance
    {
        return LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('leave_year_id', $year->id)->first();
    }

    /** @param  array<string, mixed>  $hr */
    private function unmatched(array $hr): array
    {
        return [
            'employee_id' => null, 'employee' => $hr['name'], 'code' => null, 'register_row' => $hr,
            'hr_carry_forward' => (float) $hr['carry'], 'hr_current_year' => (float) $hr['credit'], 'hr_used' => (float) $hr['used'],
            'hr_baseline' => round((float) $hr['carry'] + (float) $hr['credit'] - (float) $hr['used'], 2),
            'used_after' => null, 'adjustments_after' => null, 'accrual_after' => null, 'other_after' => null, 'pending' => null,
            'expected' => null, 'existing' => null, 'difference' => null, 'existing_lines' => null, 'apply_blocker' => null,
            'status' => self::REVIEW, 'reason' => 'No employee login matches '.implode(', ', $hr['emails']).'.',
        ];
    }

    private function onDate(LeaveYear $year): Carbon
    {
        $today = Carbon::today();

        return $today->lt($year->starts_on) ? Carbon::parse($year->starts_on) : ($today->gt($year->ends_on) ? Carbon::parse($year->ends_on) : $today);
    }

    private function n(?float $v): string
    {
        return $v === null ? '—' : (string) ($v + 0);
    }

    private function signed(?float $v): string
    {
        return $v === null ? '—' : ($v > 0 ? '+' : '').($v + 0);
    }
}
