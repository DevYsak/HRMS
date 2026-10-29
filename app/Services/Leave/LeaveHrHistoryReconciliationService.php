<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Security\PeopleScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leave V2 historical reconciliation, READ ONLY: the HR register is the
 * authoritative leave snapshot up to the cutoff (30 Sep 2026); everything
 * the portal did after it is kept. Per employee:
 *
 *   HR baseline   = HR carry forward + HR current-year earned − HR used
 *   Expected now  = HR baseline
 *                   − paid leave used after the cutoff (net of cancellations)
 *                   + HR adjustments after the cutoff
 *                   + monthly accrual for months the HR credit does not cover
 *                   (− any expiry / encashment after the cutoff)
 *   Existing now  = today's Earned Leave total available (EarnedLeaveBreakdown)
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
 * Nothing is written: plan() only reads, and the command runs it in a
 * transaction that is always rolled back.
 */
class LeaveHrHistoryReconciliationService
{
    public const MATCH = 'MATCH';

    public const DIFFERENCE = 'DIFFERENCE';

    public const MISSING = 'MISSING HR HISTORY';

    public const REVIEW = 'NEEDS REVIEW';

    /** HR's current-year credit is earned at 1 day a month from the start of the leave year. */
    private const MONTHLY_DAYS = 1.0;

    private const EPSILON = 0.005;

    /** Credits the HR baseline stands in for: never portal activity. */
    private const BASELINE_TYPES = [LeaveLedgerEntry::TYPE_OPENING, LeaveLedgerEntry::TYPE_BASE, LeaveLedgerEntry::TYPE_CARRY_FORWARD];

    private const ADJUSTMENT_TYPES = [LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT, LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT, LeaveLedgerEntry::TYPE_ADD_ON];

    public function __construct(
        private readonly LeaveRegisterReconciliationService $register,
        private readonly LeaveBalanceSource $sources,
        private readonly EarnedLeaveBreakdown $breakdown,
        private readonly LeaveV2MigrationService $migration,
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
            $rows->push($employee ? $this->planFor($employee, $hr, $year, $cutoff) : $this->unmatched($hr, $year, $cutoff));
        }

        Employee::with('user')->whereIn('status', PeopleScope::WORKING_STATUSES)->whereNotIn('id', array_keys($seen))->orderBy('employee_id')->get()
            ->filter(fn (Employee $e) => $wanted($e) && $this->sources->earnedPool($e, $this->onDate($year)) !== null)
            ->each(fn (Employee $e) => $rows->push($this->planFor($e, null, $year, $cutoff)));

        return $rows->values();
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
            'hr_carry_forward' => $hr ? round((float) $hr['carry'], 2) : null,
            'hr_current_year' => $hr ? round((float) $hr['credit'], 2) : null,
            'hr_used' => $hr ? round((float) $hr['used'], 2) : null,
            'hr_baseline' => $hr ? round((float) $hr['carry'] + (float) $hr['credit'] - (float) $hr['used'], 2) : null,
            'used_after' => 0.0, 'adjustments_after' => 0.0, 'accrual_after' => 0.0, 'other_after' => 0.0,
            'pending' => $breakdown['pending'] ?? 0.0,
            'expected' => null,
            'existing' => $breakdown['total_available'] ?? null,
            'difference' => null,
            'existing_lines' => $breakdown ? array_intersect_key($breakdown, array_flip(['carry_forward', 'current_year_accrued', 'opening', 'hr_adjustment', 'used'])) : null,
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
        $activity = $this->activity($employee, $year, $cutoff, array_values(array_filter([$pool->id, $csl?->id])), $pool);
        $covered = $hr ? $this->coveredMonths($year, (float) $hr['credit']) : [];
        $row = [
            'used_after' => $activity['used_after'],
            'adjustments_after' => $activity['adjustments_after'],
            'accrual_after' => round((float) collect($activity['accruals'])->reject(fn ($days, $month) => in_array($month, $covered ?? [], true))->sum(), 2),
            'other_after' => $activity['other_after'],
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
            .($gap ? ' Note: '.$gap : '');

        return abs((float) $row['difference']) < self::EPSILON
            ? $stop(self::MATCH, $summary)
            : $stop(self::DIFFERENCE, 'Portal shows '.$this->n($row['existing']).', expected '.$this->n($row['expected']).' ('.$this->signed($row['difference']).'). '.$summary);
    }

    /**
     * Portal movements of one employee's Earned Leave (and source CSL) in the
     * year, split at the cutoff. A reversal belongs to the date of the entry
     * it reverses, so a cancelled October day nets to zero in October.
     *
     * @param  array<int, int>  $typeIds
     * @return array{used_after: float, adjustments_after: float, other_after: float, accruals: array<string, float>, notes: array<int, string>}
     */
    private function activity(Employee $employee, LeaveYear $year, Carbon $cutoff, array $typeIds, LeaveType $pool): array
    {
        $entries = LeaveLedgerEntry::with('reverses')->where('employee_id', $employee->id)->whereIn('leave_type_id', $typeIds)
            ->where('leave_year_id', $year->id)->orderBy('id')->get()
            // The register's own postings ARE the HR history; the baseline replaces opening, base and carry forward.
            ->reject(fn (LeaveLedgerEntry $e) => ($e->reverses?->source_type ?? $e->source_type) === LeaveRegisterReconciliationService::SOURCE_TYPE
                || in_array($e->entry_type, self::BASELINE_TYPES, true));

        $dated = fn (LeaveLedgerEntry $e) => Carbon::parse(($e->reverses ?? $e)->effective_date);
        $after = $entries->filter(fn (LeaveLedgerEntry $e) => $dated($e)->gt($cutoff) || $e->entry_type === LeaveLedgerEntry::TYPE_ACCRUAL);
        $sum = fn (Collection $c, array $types) => round((float) $c->whereIn('entry_type', $types)->sum('days'), 2);

        $notes = [];
        // On the Earned pool every movement post-dates Leave V2; one dated inside the HR period may be in the register already.
        $early = $entries->filter(fn (LeaveLedgerEntry $e) => $e->leave_type_id === $pool->id && $e->entry_type !== LeaveLedgerEntry::TYPE_ACCRUAL && $dated($e)->lte($cutoff));
        if ($early->isNotEmpty()) {
            $notes[] = 'Earned Leave holds '.$early->count().' movement(s) dated on or before '.$cutoff->format('d M Y').' (#'.$early->pluck('id')->implode(', #').') — they may already be in the HR register';
        }

        $accruals = $after->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)
            ->groupBy(fn (LeaveLedgerEntry $e) => ($e->reverses ?? $e)->meta['month'] ?? $dated($e)->format('Y-m'))
            ->map(fn (Collection $g) => round((float) $g->sum('days'), 2))
            ->filter(fn (float $days) => abs($days) > self::EPSILON)
            ->sortKeys()->all();

        return [
            'used_after' => -$sum($after, [LeaveLedgerEntry::TYPE_USAGE]),
            'adjustments_after' => $sum($after, self::ADJUSTMENT_TYPES),
            'other_after' => $sum($after, [LeaveLedgerEntry::TYPE_EXPIRY, LeaveLedgerEntry::TYPE_ENCASHMENT]),
            'accruals' => $accruals,
            'notes' => $notes,
        ];
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

    /** @param  array<string, mixed>  $hr */
    private function unmatched(array $hr, LeaveYear $year, Carbon $cutoff): array
    {
        return [
            'employee_id' => null, 'employee' => $hr['name'], 'code' => null,
            'hr_carry_forward' => (float) $hr['carry'], 'hr_current_year' => (float) $hr['credit'], 'hr_used' => (float) $hr['used'],
            'hr_baseline' => round((float) $hr['carry'] + (float) $hr['credit'] - (float) $hr['used'], 2),
            'used_after' => null, 'adjustments_after' => null, 'accrual_after' => null, 'other_after' => null, 'pending' => null,
            'expected' => null, 'existing' => null, 'difference' => null, 'existing_lines' => null,
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
