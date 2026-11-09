<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
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
 * cutoff counts (leave taken, HR adjustments, monthly accrual — Earned Leave's,
 * or CSL's for a month the HR credit does not cover — expiry). Leave V2
 * mechanics (the migration opening, its reversal, the carry-forward /
 * current-year split), the register's own postings and register
 * normalisations (see normalise(): CSL was loaded from a register figure HR
 * has since corrected, and an HR adjustment brought CSL to the corrected
 * figure) are listed but not counted. Excluding never hides days: they are
 * still in today's balance, so a genuine entry read as mechanics shows up as
 * a DIFFERENCE, and apply() refuses.
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
 *     (the current-year line also carries a counted CSL monthly accrual —
 *     September — which the reversed opening had absorbed)
 *   ⇄ a portal day or HR adjustment after the cutoff still on CSL moves to
 *     Earned Leave: it is reversed on CSL and re-posted on Earned Leave with
 *     the same link (leave request / adjustment record), so a later
 *     cancellation returns it to Earned Leave
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

    /**
     * Leave taken after the cutoff that V1 charged to a reason's own old
     * balance (a Sick Leave day on Sick Leave), the only gap between expected
     * and existing: apply() moves it to Earned Leave.
     */
    public const CHARGE = 'CHARGE TO EARNED';

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

    /** An HR add / deduct / correct made through LeaveBalanceService::adjust(), with its LeaveBalanceAdjustment record. */
    private const HR_ADJUSTMENT_SOURCE = 'leave_balance_adjustment';

    // ── How each ledger entry is read (a reversal is read as the entry it reverses) ──

    /** Counted: leave taken (or returned) after the cutoff. */
    public const C_USAGE = 'portal usage';

    /** Counted: an HR add / deduct / correction after the cutoff (Earned Leave, or CSL before the migration). */
    public const C_ADJUSTMENT = 'HR adjustment';

    /** Counted: a monthly accrual — Earned Leave's, or CSL's (leave:conexus-csl-accrual) for a month the HR credit does not cover. */
    public const C_ACCRUAL = 'monthly accrual';

    /** Counted: expiry or encashment after the cutoff. */
    public const C_OTHER = 'expiry / encashment';

    /** Excluded: the register's own postings, or a restatement already made here. */
    public const C_REGISTER = 'HR register';

    /** Excluded: the Leave V2 migration opening, its reversal, the carry-forward / current-year split. */
    public const C_MECHANICS = 'migration mechanics';

    /** Excluded: CSL bookkeeping after the snapshot with no HR adjustment record or monthly-accrual key — carried into the migration. */
    public const C_BRIDGE = 'CSL bridge';

    /** Excluded: CSL movements an HR adjustment settled to bring CSL to the corrected HR register figure (normalise()). */
    public const C_NORMALISATION = 'register normalisation';

    /** Excluded: an HR credit HR marked as an opening / historical correction. */
    public const C_HISTORY_FIX = 'history correction';

    /**
     * Excluded: a CSL monthly credit and the HR deduction of the same size
     * after the cutoff that took it back (production, Oct 2026: September was
     * credited on CSL, then deducted when Earned Leave took the month over).
     * The pair nets to nothing; whether the month is credited is Earned
     * Leave's own monthly accrual to say.
     */
    public const C_WITHDRAWN = 'CSL credit withdrawn';

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
    public function plan(array $register, LeaveYear $year, Carbon $cutoff, array $codes = [], ?Carbon $asOf = null): Collection
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
                $rows->push($this->planFor($employee, $hr, $year, $cutoff, 'The register lists this employee more than once.', $asOf));

                continue;
            }
            if ($employee !== null) {
                $seen[$employee->id] = true;
            }
            $rows->push($employee ? $this->planFor($employee, $hr, $year, $cutoff, null, $asOf) : $this->unmatched($hr));
        }
        $source = (string) ($register['source'] ?? 'HR register');

        Employee::with('user')->whereIn('status', PeopleScope::WORKING_STATUSES)->whereNotIn('id', array_keys($seen))->orderBy('employee_id')->get()
            ->filter(fn (Employee $e) => $wanted($e) && $this->sources->earnedPool($e, $this->onDate($year)) !== null)
            ->each(fn (Employee $e) => $rows->push($this->planFor($e, null, $year, $cutoff, null, $asOf)));

        return $rows->map(fn (array $row) => $row + ['register_source' => $source])->values();
    }

    /**
     * @param  array<string, mixed>|null  $hr  the register row, null when the register does not list the employee
     * @param  Carbon|null  $asOf  monthly accrual is due for every month after the HR credit completed before this date (default today)
     * @return array<string, mixed>
     */
    public function planFor(Employee $employee, ?array $hr, LeaveYear $year, Carbon $cutoff, ?string $problem = null, ?Carbon $asOf = null): array
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
            'charged_elsewhere' => 0.0, 'charged_elsewhere_label' => null,
            'accrual_due' => [], 'accrual_missing' => [], 'expected_today' => null, 'next_step' => '',
            'status' => self::REVIEW, 'reason' => '',
        ];
        // By reference: a stop reports the figures gathered so far.
        $stop = function (string $status, string $reason) use (&$row, $breakdown): array {
            return $this->finish(['status' => $status, 'reason' => $reason] + $row, $breakdown);
        };

        if ($pool === null) {
            return $stop(self::REVIEW, 'The employee\'s policy has no Earned Leave pool.');
        }

        // Portal activity: the Earned Leave pool, and the CSL it was migrated from.
        $csl = $this->migration->sourceType();
        $covered = $hr ? $this->coveredMonths($year, (float) $hr['credit']) : [];
        $activity = $this->activity($employee, $year, $cutoff, $pool, $csl, $row['hr_baseline'], $covered);
        $row = [
            'used_after' => $activity['used_after'],
            'adjustments_after' => $activity['adjustments_after'],
            'accrual_after' => round((float) collect($activity['accruals'])->reject(fn ($days, $month) => in_array($month, $covered ?? [], true))->sum(), 2),
            'other_after' => $activity['other_after'],
            'excluded_after' => $activity['excluded_after'],
            'entries' => $activity['entries'],
            'charged_elsewhere' => $activity['charged_elsewhere'],
            'charged_elsewhere_label' => $activity['charged_elsewhere_label'],
        ] + $row;
        // The HR credit stands for July and August; every month completed
        // since is due one day (credited on the 1st of the next month).
        if ($hr !== null && $covered !== null) {
            $row['accrual_due'] = $this->dueMonths($employee, $year, $covered, $asOf ?? Carbon::now());
            $row['accrual_missing'] = array_values(array_filter($row['accrual_due'],
                fn (string $month) => ($activity['accruals'][$month] ?? 0.0) < self::MONTHLY_DAYS - self::EPSILON));
        }

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
        // A month credited on CSL (absorbed into the opening) and again on Earned Leave.
        $twice = array_keys(array_filter($activity['accruals'], fn (float $days) => $days > self::MONTHLY_DAYS + self::EPSILON));
        if ($twice !== []) {
            $notes[] = implode(', ', $twice).' is credited more than once (CSL and Earned Leave monthly accrual)';
        }
        if ($row['expected'] < -self::EPSILON) {
            $notes[] = 'the expected balance is negative ('.$this->n($row['expected']).') — Earned Leave is never restated below zero';
        }
        if (! ($breakdown['has_balance'] ?? false)) {
            $notes[] = 'no Earned Leave balance exists for '.$year->label;
        }
        if ($notes !== []) {
            return $stop(self::REVIEW, ucfirst(implode('; ', $notes)).'.');
        }

        $gap = $this->accrualGap($covered, $breakdown['starts_from'] ?? null, $activity['accruals']);
        $summary = sprintf('HR %s + %s − %s = %s; after %s: −%s used, %s HR, +%s accrual.',
            $this->n($row['hr_carry_forward']), $this->n($row['hr_current_year']), $this->n($row['hr_used']), $this->n($row['hr_baseline']),
            $cutoff->format('d M'), $this->n($row['used_after']), $this->signed($row['adjustments_after']), $this->n($row['accrual_after']))
            .(abs($row['excluded_after']) > self::EPSILON ? ' Not counted (CSL bridge / migration corrections): '.$this->signed($row['excluded_after']).'.' : '')
            .($gap ? ' Note: '.$gap : '');

        $info = $activity['unread'] !== []
            ? ' Not read: '.implode('; ', $activity['unread']).' after '.$cutoff->format('d M').' — charged to its own balance, not Earned Leave.'
            : '';
        $summary .= $info;

        if (abs((float) $row['difference']) >= self::EPSILON) {
            // The only gap is leave V1 charged to a reason's own old balance.
            if ($row['charged_elsewhere'] > self::EPSILON && abs($row['difference'] - $row['charged_elsewhere']) < self::EPSILON) {
                $row['apply_blocker'] = $this->shapeProblem($employee, $year, $pool, $activity['classified'], $this->applied($employee, $year));

                return $stop(self::CHARGE, $this->n($row['charged_elsewhere']).' day(s) taken after '.$cutoff->format('d M').' ('.$row['charged_elsewhere_label'].') are still charged to the old balance, not Earned Leave. '
                    .'Apply moves them: Earned Leave '.$this->n($row['existing']).' → '.$this->n($row['expected']).'. '.$summary
                    .($row['apply_blocker'] ? ' Apply would refuse: '.$row['apply_blocker'] : ''));
            }

            $drift = $activity['loaded'] !== null && abs($activity['loaded'] - $row['hr_baseline']) >= self::EPSILON && ! $activity['normalised']
                ? ' CSL was loaded with '.$this->n($activity['loaded']).' for the HR period but the register now says '.$this->n($row['hr_baseline'])
                    .', and no HR adjustment after the snapshot brings CSL to it.'
                : '';

            return $stop(self::DIFFERENCE, 'Portal shows '.$this->n($row['existing']).', expected '.$this->n($row['expected']).' ('.$this->signed($row['difference']).'). '.$summary.$drift);
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

            $legacyIds = $this->legacyReasonTypes($pool, $csl)->keys()->all();
            if ($legacyIds !== []) {
                LeaveBalance::where('employee_id', $employee->id)->whereIn('leave_type_id', $legacyIds)
                    ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))->lockForUpdate()->get();
            }

            $row = $this->planFor($employee, $planned['register_row'], $year, $cutoff);
            $gap = $row['status'] === self::CHARGE ? (float) $row['charged_elsewhere'] : 0.0;
            if (! in_array($row['status'], [self::MATCH, self::CHARGE], true) || abs((float) $row['difference'] - $gap) >= self::EPSILON || $row['apply_blocker'] !== null) {
                return $row;
            }
            $restated = $this->applied($employee, $year);

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
            //    breakdown: each is reversed, never edited. (Already restated: only
            //    step 3 runs.)
            $classified = $this->activity($employee, $year, $cutoff, $pool, $csl, $row['hr_baseline'], $this->coveredMonths($year, (float) $row['hr_current_year']) ?? [])['classified'];
            foreach ($restated ? [] : $this->live($employee, $pool, $year)->whereIn('entry_type', self::BASELINE_TYPES) as $line) {
                $posted['baseline_reversals'][] = $this->ledger->reverse($line, 'Replaced by the HR register breakdown — '.$why, $actor, ['meta' => $meta])->id;
            }
            // 2. The HR snapshot, as its own lines. A counted CSL monthly accrual
            //    (September) was absorbed into the reversed opening, so it rides
            //    on the current-year line; the CSL entry itself stays as it is.
            $cslAccruals = $classified->filter(fn ($c) => $c['entry']->leave_type_id !== $pool->id && $c['class'] === self::C_ACCRUAL);
            $cslAccrued = round((float) $cslAccruals->sum(fn ($c) => (float) $c['entry']->days), 2);
            $cslMonths = $cslAccruals->map(fn ($c) => ($c['entry']->reverses ?? $c['entry'])->meta['month'] ?? null)->filter()->unique()->sort()->values()->all();
            $start = Carbon::parse($year->starts_on);
            foreach ($restated ? [] : [['carry_forward', LeaveLedgerEntry::TYPE_CARRY_FORWARD, $row['hr_carry_forward'], 'Carry Forward (HR register)', []],
                ['current_year', LeaveLedgerEntry::TYPE_BASE, round($row['hr_current_year'] + $cslAccrued, 2),
                    $year->label.' earned to '.$cutoff->format('d M Y').' (HR register)'.($cslAccrued > self::EPSILON ? ' + '.implode(', ', $cslMonths).' CSL monthly accrual' : ''),
                    $cslAccrued > self::EPSILON ? ['csl_accrual' => $cslAccrued, 'csl_accrual_entries' => $cslAccruals->pluck('entry.id')->all()] : []]] as [$part, $type, $days, $label, $extra]) {
                if ($days > self::EPSILON) {
                    $posted[$part] = $this->ledger->credit($earnedBalance->fresh(), $type, $days, $start, $key($part), $common + ['reason' => $label, 'meta' => $meta + ['part' => $part] + $extra])->id;
                }
            }
            if (! $restated && $row['hr_used'] > self::EPSILON) {
                $posted['historical_used'] = $this->ledger->debit($earnedBalance->fresh(), LeaveLedgerEntry::TYPE_USAGE, $row['hr_used'], $cutoff, $key('historical_used'),
                    $common + ['reason' => 'Leave used to '.$cutoff->format('d M Y').' (HR register)', 'meta' => $meta + ['part' => 'historical_used', 'historical' => true]])->id;
            }
            // 3. Portal days and HR adjustments after the cutoff still on CSL — and
            //    leave V1 charged to a reason's own old balance (Sick Leave) — move
            //    to Earned Leave, keeping their link (leave request / adjustment
            //    record), so a later cancellation returns them to Earned Leave.
            //    The request itself is not touched.
            $moved = 0.0;
            $touched = [];
            foreach ($this->cslMoves($classified, $pool) as $entry) {
                if ($entry->leave_type_id !== $csl?->id) {
                    $moved = round($moved - (float) $entry->days, 2);
                    $touched[$entry->leave_type_id] = $entry->leave_year_id;
                }
                $this->ledger->reverse($entry, 'Moved to Earned Leave — '.$why, $actor, ['meta' => $meta]);
                $options = ['source_type' => $entry->source_type, 'source_id' => $entry->source_id, 'actor' => $actor,
                    'reason' => $entry->reason, 'meta' => ($entry->meta ?? []) + ['moved_from_entry' => $entry->id, 'hr_history' => true]];
                $posted['moved'][] = ((float) $entry->days > 0
                    ? $this->ledger->credit($earnedBalance->fresh(), $entry->entry_type, (float) $entry->days, Carbon::parse($entry->effective_date), $key('moved:'.$entry->id), $options)
                    : $this->ledger->debit($earnedBalance->fresh(), $entry->entry_type, -(float) $entry->days, Carbon::parse($entry->effective_date), $key('moved:'.$entry->id), $options))->id;
            }
            $this->ledger->rebuild($earnedBalance->fresh());
            if ($cslBalance) {
                $this->ledger->rebuild($cslBalance->fresh());
            }
            foreach ($touched as $typeId => $yearId) {
                $this->ledger->rebuild(LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $typeId)
                    ->where(fn ($q) => $q->where('leave_year_id', $yearId)->orWhere('year', LeaveYear::find($yearId)?->legacyYear()))->firstOrFail());
            }

            // Restating never changes the total; only the moved old-balance days do.
            $after = $this->breakdown->for($employee, $year, $pool);
            if (abs($after['total_available'] - ($before['total_available'] - $moved)) >= self::EPSILON || abs($moved - $gap) >= self::EPSILON || ! $after['consistent']
                || abs($after['total_available'] - (float) $row['expected']) >= self::EPSILON || abs($after['opening']) >= self::EPSILON) {
                throw new RuntimeException("Restating {$employee->employee_id} would change the balance ({$before['total_available']} → {$after['total_available']}, expected {$row['expected']}); nothing was kept.");
            }

            app(AuditService::class)->event($restated ? 'LEAVE_CHARGED_TO_EARNED' : 'LEAVE_HR_HISTORY_RECONCILED', AuditService::LEAVE, $earnedBalance->fresh(),
                old: ['breakdown' => $this->linesOf($before)],
                new: ['breakdown' => $this->linesOf($after), 'entries' => $posted, 'cutoff' => $cutoff->toDateString(), 'register' => $meta['hr'],
                    'register_source' => $meta['register_source'], 'actor' => $actor->email, 'moved_to_earned' => $moved],
                reason: $why, subjectEmployeeId: $employee->id, actor: $actor);

            $movedText = $moved > self::EPSILON
                ? $this->n($moved).' day(s) of '.$row['charged_elsewhere_label'].' moved to Earned Leave; available '.$this->n($before['total_available']).' → '.$this->n($after['total_available']).'.'
                : '';

            return ['status' => self::RECONCILED,
                'reason' => $restated ? $movedText : trim('Restated to the HR register; '.($moved > self::EPSILON ? $movedText : 'available unchanged at '.$this->n($after['total_available']).'.')),
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
    private function shapeProblem(Employee $employee, LeaveYear $year, LeaveType $pool, Collection $classified, bool $restated = false): ?string
    {
        if ($restated) {
            $unmovable = $classified->filter(fn ($c) => $c['entry']->leave_type_id !== $pool->id && $c['class'] === self::C_USAGE
                && $this->isLive($c, $classified) && ! $this->cslMoves($classified, $pool)->contains('id', $c['entry']->id));

            return $unmovable->isEmpty() ? null : 'returned days on the old balance after the snapshot (#'.$unmovable->pluck('entry.id')->implode(', #').') cannot be moved safely.';
        }
        if (! LeaveV2Migration::where('employee_id', $employee->id)->where('earned_leave_type_id', $pool->id)->where('leave_year_id', $year->id)->exists()) {
            return 'no Leave V2 migration record — the Earned Leave history was built another way.';
        }

        $baseline = $classified->filter(fn ($c) => $c['entry']->leave_type_id === $pool->id && in_array($c['entry']->entry_type, self::BASELINE_TYPES, true) && $this->isLive($c, $classified));
        if ($odd = $baseline->reject(fn ($c) => $c['class'] === self::C_MECHANICS)->pluck('entry')->all()) {
            return 'Earned Leave baseline lines not from the migration (#'.collect($odd)->pluck('id')->implode(', #').').';
        }
        $moves = $this->cslMoves($classified, $pool)->pluck('id')->all();
        $unmovable = $classified->filter(fn ($c) => $c['entry']->leave_type_id !== $pool->id && in_array($c['class'], [self::C_USAGE, self::C_ADJUSTMENT, self::C_OTHER], true)
            && $this->isLive($c, $classified) && ! in_array($c['entry']->id, $moves, true));
        if ($unmovable->isNotEmpty()) {
            return 'CSL holds returned days or other movements after the snapshot (#'.$unmovable->pluck('entry.id')->implode(', #').') — they cannot be moved safely.';
        }

        return null;
    }

    /**
     * Live CSL business after the cutoff that apply() moves to Earned Leave:
     * leave taken (linked to its request) and HR adjustments (linked to their
     * LeaveBalanceAdjustment record).
     *
     * @param  Collection<int, array{entry: LeaveLedgerEntry, class: string}>  $classified
     * @return Collection<int, LeaveLedgerEntry>
     */
    private function cslMoves(Collection $classified, LeaveType $pool): Collection
    {
        return $classified->filter(fn ($c) => $c['entry']->leave_type_id !== $pool->id && $this->isLive($c, $classified)
            && (($c['class'] === self::C_USAGE && $c['entry']->entry_type === LeaveLedgerEntry::TYPE_USAGE)
                || ($c['class'] === self::C_ADJUSTMENT && $c['entry']->source_type === self::HR_ADJUSTMENT_SOURCE)))
            ->pluck('entry')->values();
    }

    /** @param  Collection<int, array{entry: LeaveLedgerEntry, class: string}>  $classified */
    private function isLive(array $c, Collection $classified): bool
    {
        return $c['entry']->reverses_entry_id === null && ! $classified->contains(fn ($o) => $o['entry']->reverses_entry_id === $c['entry']->id);
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
     * @param  float|null  $baseline  the HR register baseline at the cutoff (null: not in the register)
     * @param  array<int, string>  $covered  the months the HR current-year credit stands for
     * @return array{used_after: float, adjustments_after: float, other_after: float, excluded_after: float, accruals: array<string, float>,
     *     loaded: float|null, normalised: bool, notes: array<int, string>, classified: Collection<int, array{entry: LeaveLedgerEntry, class: string}>,
     *     entries: array<int, array<string, mixed>>}
     */
    private function activity(Employee $employee, LeaveYear $year, Carbon $cutoff, LeaveType $pool, ?LeaveType $csl, ?float $baseline = null, array $covered = []): array
    {
        $classified = LeaveLedgerEntry::with('reverses')->where('employee_id', $employee->id)
            ->whereIn('leave_type_id', array_values(array_filter([$pool->id, $csl?->id])))
            ->where('leave_year_id', $year->id)->orderBy('id')->get()
            ->map(fn (LeaveLedgerEntry $e) => ['entry' => $e, 'class' => $this->classify($e, $pool, $cutoff)]);
        [$classified, $loaded, $normalisedAt] = $this->normalise($classified, $pool, $baseline);
        $classified = $this->pairWithdrawn($classified, $pool);

        // Leave taken after the cutoff that V1 charged to a reason's own old
        // balance (a Sick Leave day on Sick Leave). Every reason draws on
        // Earned Leave, and the register counts every day taken, so it is
        // Earned Leave's day: counted here, moved there by apply().
        $reasons = $this->legacyReasonTypes($pool, $csl);
        $legacy = $reasons->isEmpty() ? collect() : LeaveLedgerEntry::with('reverses')->where('employee_id', $employee->id)
            ->whereIn('leave_type_id', $reasons->keys()->all())->where('leave_year_id', $year->id)->orderBy('id')->get()
            ->filter(fn (LeaveLedgerEntry $e) => $this->isLeaveTaken($e) && Carbon::parse(($e->reverses ?? $e)->effective_date)->gt($cutoff))
            ->map(fn (LeaveLedgerEntry $e) => ['entry' => $e, 'class' => self::C_USAGE])
            ->values();
        $classified = $classified->concat($legacy)->values();
        $chargedElsewhere = round(-(float) $legacy->sum(fn ($c) => (float) $c['entry']->days), 2);
        $requests = $legacy->filter(fn ($c) => $this->isLive($c, $legacy))->map(fn ($c) => $c['entry']->source_type === 'leave_request' ? 'LR-'.str_pad((string) $c['entry']->source_id, 4, '0', STR_PAD_LEFT) : null)->filter()->unique()->values();
        $chargedLabel = $legacy->isEmpty() ? null
            : $legacy->map(fn ($c) => $reasons->get($c['entry']->leave_type_id)?->name)->filter()->unique()->implode(', ')
                .($requests->isNotEmpty() ? ' '.$requests->implode(', ') : '');

        // Leave after the cutoff on any other paid type with its own balance:
        // never counted — listed so HR can see it.
        $unread = LeaveLedgerEntry::with('reverses')->where('employee_id', $employee->id)->where('leave_year_id', $year->id)
            ->whereNotIn('leave_type_id', array_merge([$pool->id], array_filter([$csl?->id]), $reasons->keys()->all()))->get()
            ->filter(fn (LeaveLedgerEntry $e) => $this->isLeaveTaken($e) && Carbon::parse(($e->reverses ?? $e)->effective_date)->gt($cutoff));
        $unreadTypes = LeaveType::withTrashed()->whereKey($unread->pluck('leave_type_id')->unique()->all())->get()
            ->filter(fn (LeaveType $t) => $t->is_paid && ! in_array(LeaveCategory::of($t), [LeaveCategory::SPECIAL, LeaveCategory::COMP_OFF, LeaveCategory::UNPAID], true))
            ->keyBy('id');
        $unreadText = $unread->filter(fn (LeaveLedgerEntry $e) => $unreadTypes->has($e->leave_type_id))->groupBy('leave_type_id')
            ->map(fn (Collection $g, $typeId) => ['days' => round(-(float) $g->sum('days'), 2), 'name' => $unreadTypes[$typeId]->name])
            ->filter(fn (array $u) => abs($u['days']) > self::EPSILON)
            ->map(fn (array $u) => $this->n($u['days']).' day(s) of '.$u['name'])->values()->all();
        $records = LeaveBalanceAdjustment::whereIn('id', $classified->map(fn ($c) => $c['entry']->reverses ?? $c['entry'])
            ->where('source_type', self::HR_ADJUSTMENT_SOURCE)->pluck('source_id')->filter()->unique()->all())->get()->keyBy('id');
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
            'charged_elsewhere' => $chargedElsewhere + 0.0,
            'charged_elsewhere_label' => $chargedLabel,
            'unread' => $unreadText,
            'used_after' => -$sum([self::C_USAGE]) + 0.0,
            'adjustments_after' => $sum([self::C_ADJUSTMENT]),
            'other_after' => $sum([self::C_OTHER]),
            'excluded_after' => $sum([self::C_BRIDGE, self::C_HISTORY_FIX, self::C_NORMALISATION, self::C_WITHDRAWN]),
            'accruals' => $accruals,
            'loaded' => $loaded,
            'normalised' => $normalisedAt !== null,
            'notes' => $notes,
            'classified' => $classified,
            'entries' => $classified->map(fn ($c) => [
                'id' => $c['entry']->id, 'ledger' => $c['entry']->leave_type_id === $pool->id ? 'Earned' : ($reasons->get($c['entry']->leave_type_id)?->name ?? 'CSL'),
                'type' => $c['entry']->entry_type, 'days' => (float) $c['entry']->days,
                'date' => Carbon::parse(($c['entry']->reverses ?? $c['entry'])->effective_date)->toDateString(),
                'source' => $c['entry']->source_type, 'reverses' => $c['entry']->reverses_entry_id,
                'class' => $c['class'], 'counted' => in_array($c['class'], self::COUNTED, true),
                'why' => $this->why($c, $cutoff, $covered, $records, $loaded, $baseline, $normalisedAt),
            ])->values()->all(),
        ];
    }

    /**
     * A register normalisation. When CSL was loaded for the HR period with a
     * figure HR has since corrected (old register 4.5, corrected 2.5), HR
     * fixed CSL by hand after the snapshot. Read in date order from the
     * loaded figure, the first HR adjustment that leaves CSL at exactly the
     * corrected HR baseline is that fix: it — and the CSL accruals and
     * adjustments before it, which it settled — restate history the register
     * now states, so none of them count. When CSL was loaded with the HR
     * figure there is nothing to normalise, and every HR adjustment counts as
     * the business it is. An adjustment that does not land on the HR figure
     * is never a normalisation, whatever its category or reason.
     *
     * @param  Collection<int, array{entry: LeaveLedgerEntry, class: string}>  $classified
     * @return array{0: Collection<int, array{entry: LeaveLedgerEntry, class: string}>, 1: float|null, 2: int|null} the entries, the CSL figure loaded for the HR period, the normalising entry
     */
    private function normalise(Collection $classified, LeaveType $pool, ?float $baseline): array
    {
        $csl = $classified->filter(fn ($c) => $c['entry']->leave_type_id !== $pool->id);
        if ($csl->isEmpty()) {
            return [$classified, null, null];
        }
        $loaded = round((float) $csl->whereIn('class', [self::C_REGISTER, self::C_HR_PERIOD])->sum(fn ($c) => (float) $c['entry']->days), 2);
        if ($baseline === null || abs($loaded - $baseline) < self::EPSILON) {
            return [$classified, $loaded, null];
        }

        $running = $loaded;
        $settled = [];
        $at = null;
        // CSL bookkeeping without an adjustment record is excluded anyway, but it can still be the fix that settles an accrual before it.
        $candidates = $csl->filter(fn ($c) => in_array($c['class'], [self::C_ACCRUAL, self::C_ADJUSTMENT, self::C_BRIDGE], true))
            ->sortBy(fn ($c) => Carbon::parse(($c['entry']->reverses ?? $c['entry'])->effective_date)->toDateString().'|'.str_pad((string) $c['entry']->id, 12, '0', STR_PAD_LEFT));
        foreach ($candidates as $c) {
            $running = round($running + (float) $c['entry']->days, 2);
            $settled[] = $c['entry']->id;
            if (in_array($c['entry']->entry_type, self::ADJUSTMENT_TYPES, true) && abs($running - $baseline) < self::EPSILON) {
                $at = $c['entry']->id;
                break;
            }
        }
        if ($at === null) {
            return [$classified, $loaded, null];
        }

        return [$classified->map(fn ($c) => in_array($c['entry']->id, $settled, true) ? ['class' => self::C_NORMALISATION] + $c : $c), $loaded, $at];
    }

    /**
     * A live CSL monthly accrual and a live CSL HR deduction of exactly the
     * same days after the cutoff (the deduction that took the credit back)
     * are read as one withdrawn credit: neither counts. Each deduction pairs
     * with one credit, in date order; anything unpaired keeps its reading.
     *
     * @param  Collection<int, array{entry: LeaveLedgerEntry, class: string}>  $classified
     * @return Collection<int, array{entry: LeaveLedgerEntry, class: string}>
     */
    private function pairWithdrawn(Collection $classified, LeaveType $pool): Collection
    {
        $onCsl = fn (array $c) => $c['entry']->leave_type_id !== $pool->id && $c['entry']->reverses_entry_id === null && $this->isLive($c, $classified);
        $paired = [];
        foreach ($classified->filter(fn ($c) => $onCsl($c) && $c['class'] === self::C_ACCRUAL && (float) $c['entry']->days > self::EPSILON) as $credit) {
            $deduction = $classified->first(fn ($c) => $onCsl($c) && $c['class'] === self::C_ADJUSTMENT && ! in_array($c['entry']->id, $paired, true)
                && abs((float) $c['entry']->days + (float) $credit['entry']->days) < self::EPSILON);
            if ($deduction !== null) {
                $paired[] = $credit['entry']->id;
                $paired[] = $deduction['entry']->id;
            }
        }

        return $paired === [] ? $classified
            : $classified->map(fn ($c) => in_array($c['entry']->id, $paired, true) ? ['class' => self::C_WITHDRAWN] + $c : $c);
    }

    /**
     * Why one entry counts or not, for --details.
     *
     * @param  array{entry: LeaveLedgerEntry, class: string}  $c
     * @param  array<int, string>  $covered
     * @param  Collection<int, LeaveBalanceAdjustment>  $records
     */
    private function why(array $c, Carbon $cutoff, array $covered, Collection $records, ?float $loaded, ?float $baseline, ?int $normalisedAt): string
    {
        $original = $c['entry']->reverses ?? $c['entry'];
        $record = $original->source_type === self::HR_ADJUSTMENT_SOURCE ? $records->get($original->source_id) : null;
        $hr = $record ? ' — '.($record->category ?? 'adjustment').' #'.$record->id.' by user '.$record->adjusted_by.': "'.$record->reason.'"' : '';
        $month = $original->meta['month'] ?? Carbon::parse($original->effective_date)->format('Y-m');
        $prefix = $c['entry']->reverses_entry_id ? 'reversal; ' : '';

        return $prefix.match ($c['class']) {
            self::C_REGISTER => $original->source_type === self::SOURCE_TYPE ? 'posted by this restatement' : 'the HR register\'s own posting',
            self::C_MECHANICS => 'Leave V2 bookkeeping — the HR baseline replaces it',
            self::C_HR_PERIOD => 'dated on or before '.$cutoff->format('d M Y').' — inside the HR register',
            self::C_NORMALISATION => ($c['entry']->id === $normalisedAt ? 'brings CSL from the loaded '.$this->n($loaded).' to the HR register\'s '.$this->n($baseline) : 'settled by the register normalisation #'.$normalisedAt).$hr,
            self::C_BRIDGE => 'CSL bookkeeping with no HR adjustment record or monthly-accrual key — carried into the migration',
            self::C_WITHDRAWN => ($original->entry_type === LeaveLedgerEntry::TYPE_ACCRUAL
                ? 'CSL credit for '.$month.' taken back by an HR deduction of the same size'
                : 'takes back a CSL monthly credit of the same size').' — nets to nothing; Earned Leave\'s own accrual decides the month'.$hr,
            self::C_HISTORY_FIX => 'HR marked it '.($original->meta['add_on_type'] ?? 'a history correction').' — the register states that history'.$hr,
            self::C_ACCRUAL => 'monthly accrual for '.$month.(in_array($month, $covered, true) ? ' — the HR credit already covers it' : ' — not covered by the HR credit'),
            self::C_ADJUSTMENT => 'HR adjustment after '.$cutoff->format('d M').$hr,
            self::C_USAGE => 'leave taken after '.$cutoff->format('d M').($original->source_id ? ' ('.str_replace('_', ' ', (string) $original->source_type).' #'.$original->source_id.')' : ''),
            self::C_OTHER => 'expiry / encashment after '.$cutoff->format('d M'),
            self::C_EARLY => 'Earned Leave movement inside the HR period — may already be in the register',
            default => 'cannot be read safely',
        };
    }

    /**
     * What one ledger entry is, for the reconciliation. A reversal is read as
     * the entry it reverses (its source and date), so a cancellation nets to
     * zero in the same place as the day it cancels.
     *
     * Earned Leave: monthly accruals, HR adjustments, leave taken and
     * expiry after the cutoff are business; the migration opening and its
     * carry-forward / current-year split are mechanics. CSL (it sits inside
     * the migrated opening): leave taken after the cutoff, its monthly
     * accrual (leave:conexus-csl-accrual — September, effective 30 Sep) and
     * HR adjustments with their adjustment record after the cutoff are
     * business, unless normalise() finds they restate the HR period; other
     * CSL bookkeeping after the snapshot is the bridge to the migration.
     * HR credits marked as opening / historical corrections restate history
     * on either ledger. Anything else is unsupported — NEEDS REVIEW, never
     * guessed.
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
        $history = in_array($original->meta['add_on_type'] ?? null, self::HISTORY_KINDS, true);

        return match (true) {
            in_array($source, [LeaveRegisterReconciliationService::SOURCE_TYPE, self::SOURCE_TYPE], true) => self::C_REGISTER,
            in_array($source, self::MECHANIC_SOURCES, true) => self::C_MECHANICS,
            // CSL
            ! $onPool && $type === LeaveLedgerEntry::TYPE_ACCRUAL => str_starts_with((string) $original->idempotency_key, ConexusCslAccrualService::KEY_PREFIX.':')
                ? self::C_ACCRUAL : self::C_BRIDGE,
            ! $onPool && ! $after => self::C_HR_PERIOD,
            ! $onPool && $usage => self::C_USAGE,
            ! $onPool && in_array($type, self::ADJUSTMENT_TYPES, true) => match (true) {
                $history => self::C_HISTORY_FIX,
                $source === self::HR_ADJUSTMENT_SOURCE && $original->source_id !== null => self::C_ADJUSTMENT,
                default => self::C_BRIDGE,
            },
            ! $onPool => self::C_UNSUPPORTED,
            // Earned Leave
            $type === LeaveLedgerEntry::TYPE_ACCRUAL => str_starts_with((string) $original->idempotency_key, EarnedLeaveAccrualService::KEY_PREFIX.':') ? self::C_ACCRUAL : self::C_UNSUPPORTED,
            in_array($type, self::BASELINE_TYPES, true) => self::C_UNSUPPORTED,
            ! $after => self::C_EARLY,
            $usage => self::C_USAGE,
            in_array($type, self::ADJUSTMENT_TYPES, true) => $history ? self::C_HISTORY_FIX : self::C_ADJUSTMENT,
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

    /**
     * The months after the HR credit, completed before $asOf, that the
     * employee worked through: each is due one day of monthly accrual.
     *
     * @param  array<int, string>  $covered
     * @return array<int, string> Y-m
     */
    private function dueMonths(Employee $employee, LeaveYear $year, array $covered, Carbon $asOf): array
    {
        $month = $covered === [] ? Carbon::parse($year->starts_on)->startOfMonth() : Carbon::parse(end($covered).'-01')->addMonthNoOverflow();
        $joined = $employee->joining_date ? Carbon::parse($employee->joining_date)->startOfDay() : null;
        $lastDay = $employee->exitRecord?->last_working_day ? Carbon::parse($employee->exitRecord->last_working_day)->startOfDay() : null;
        $today = $asOf->copy()->startOfDay();
        $yearEnd = Carbon::parse($year->ends_on)->startOfDay();
        $due = [];

        while ($month->copy()->endOfMonth()->lt($today) && $month->lte($yearEnd) && count($covered) + count($due) < 12) {
            $end = $month->copy()->endOfMonth()->startOfDay();
            if ($lastDay !== null && $lastDay->lt($end)) {
                break;
            }
            if ($joined === null || $joined->lte($month)) {
                $due[] = $month->format('Y-m');
            }
            $month = $month->copy()->addMonthNoOverflow();
        }

        return $due;
    }

    /**
     * The expected figure with the monthly accrual still to be credited,
     * and the next step for the row.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $breakdown
     * @return array<string, mixed>
     */
    private function finish(array $row, ?array $breakdown): array
    {
        $missing = $row['accrual_missing'] ?? [];
        $row['expected_today'] = $row['expected'] === null ? null : round((float) $row['expected'] + count($missing) * self::MONTHLY_DAYS, 2);

        // Missing monthly accrual comes first: credit it, then read the row again.
        $steps = [];
        if ($missing !== []) {
            $first = Carbon::parse($missing[0].'-01');
            $starts = $breakdown['starts_from'] ?? null;
            $steps[] = match (true) {
                $starts === null => 'set Monthly Accrual Starts From to '.$first->format('M Y').' (employee\'s leave page)',
                Carbon::parse($starts)->format('Y-m') > $missing[0] => 'move Monthly Accrual Starts From back to '.$first->format('M Y'),
                default => null,
            };
            $steps[] = 'php artisan leave:accrue-earned --apply --employee='.($row['code'] ?? '');
            $steps[] = 're-run this preview';
            $row['reason'] = trim($row['reason'].' Monthly accrual not credited for '
                .collect($missing)->map(fn (string $m) => Carbon::parse($m.'-01')->format('M Y'))->implode(', ')
                .' (+'.$this->n(count($missing) * self::MONTHLY_DAYS).' when credited).');
        } else {
            $steps[] = match ($row['status']) {
                self::MATCH => 'apply — restate to the HR register (available unchanged)',
                self::CHARGE => 'apply — move '.$this->n($row['charged_elsewhere']).' day(s) to Earned Leave',
                self::NO_CHANGE => null,
                self::DIFFERENCE => 'HR review — find the cause, then correct the balance on the employee\'s leave page',
                default => 'HR review — see reason',
            };
        }
        $row['next_step'] = implode(' → ', array_filter($steps)) ?: '—';

        return $row;
    }

    /** A reason that draws on Earned Leave under V2 (Casual, Sick…), other than the pool and CSL: its own old balance is history. */
    private function legacyReasonTypes(LeaveType $pool, ?LeaveType $csl): Collection
    {
        return LeaveType::withTrashed()->whereKeyNot(array_values(array_filter([$pool->id, $csl?->id])))->get()
            ->filter(fn (LeaveType $t) => $this->sources->forPool($t, $pool)['source'] === LeaveBalanceSource::EARNED_POOL)
            ->keyBy('id');
    }

    /** Leave taken (a linked usage debit), its reversal, or days given back on cancellation. */
    private function isLeaveTaken(LeaveLedgerEntry $entry): bool
    {
        $original = $entry->reverses ?? $entry;

        return ($original->entry_type === LeaveLedgerEntry::TYPE_USAGE && in_array($original->source_type, self::LINKED_USAGE, true))
            || ($original->entry_type === LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT && $original->source_type === self::USAGE_RETURN);
    }

    /** A month earned by neither the HR credit nor monthly accrual. */
    private function accrualGap(?array $covered, ?string $startsFrom, array $accruals = []): ?string
    {
        if ($covered === null || $startsFrom === null) {
            return null;
        }
        $next = $covered === [] ? null : Carbon::parse(end($covered).'-01')->addMonthNoOverflow()->format('Y-m');
        // Credited after all (a CSL monthly accrual for that month counts).
        if ($next !== null && ($accruals[$next] ?? 0.0) >= self::MONTHLY_DAYS - self::EPSILON) {
            return null;
        }
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
