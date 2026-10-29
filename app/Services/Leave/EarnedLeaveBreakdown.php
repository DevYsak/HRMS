<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use Illuminate\Support\Carbon;

/**
 * The one breakdown of an employee's Earned Leave for a leave year — every
 * screen (HR grid, HR employee page, My Time Off, forecast) reads it here:
 *
 *     Carry Forward                 previous years' unused Earned Leave
 *   + Current Year Accrued          this year's NEW entitlement (monthly
 *                                   credits + any up-front base; ≤ 12)
 *   + Migrated Opening              a Leave V2 migration balance whose
 *                                   origin has not been classified
 *   + HR Adjustments (net)          HR add / deduct / correction, add-ons
 *   − Used − Encashed − Expired
 *   = Total Available               (LeaveBalanceCalculator's approved
 *                                   available; pending is shown beside it)
 *
 * The lines are the ledger buckets, never re-derived: the total is the
 * calculator's figure and "consistent" says the lines add up to it. Only
 * Current Year Accrued is limited to 12 a year — the total may exceed it.
 */
class EarnedLeaveBreakdown
{
    private const EPSILON = 0.005;

    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveBalanceSource $sources,
        private readonly EarnedLeaveAccrualService $accrual,
        private readonly ConexusCslAccrualService $months,
    ) {}

    /**
     * The source lines of one balance from its calculator summary.
     *
     * @param  array<string, mixed>|null  $summary  LeaveBalanceCalculator::summary(), null for no balance
     * @return array{carry_forward: float, current_year_accrued: float, opening: float, hr_adjustment: float, used: float, encashed: float, expired: float, pending: float, total_available: float, available_to_request: float, consistent: bool}
     */
    public static function lines(?array $summary): array
    {
        $f = fn (string $key) => round((float) ($summary[$key] ?? 0), 2);

        $lines = [
            'carry_forward' => $f('carry_forward'),
            'current_year_accrued' => round($f('base') + $f('accrued'), 2),
            'opening' => $f('opening'),
            'hr_adjustment' => round($f('add_on') + $f('adjustment_credit') - $f('adjustment_debit'), 2),
            'used' => $f('used'),
            'encashed' => $f('encashed'),
            'expired' => $f('expired'),
            'pending' => $f('pending'),
        ];
        $sum = round($lines['carry_forward'] + $lines['current_year_accrued'] + $lines['opening'] + $lines['hr_adjustment']
            - $lines['used'] - $lines['encashed'] - $lines['expired'], 2);
        $total = $summary === null ? 0.0 : $f('approved_available');

        return $lines + [
            'total_available' => $total,
            'available_to_request' => $summary === null ? 0.0 : $f('available_to_request'),
            'consistent' => abs($sum - $total) < self::EPSILON,
        ];
    }

    /**
     * The breakdown with its accrual context: the monthly rule, Monthly
     * Accrual Starts From, the next credit and the year-end forecast. Null
     * when the employee's policy has no Earned Leave balance.
     *
     * @return array<string, mixed>|null
     */
    public function for(Employee $employee, LeaveYear $year, ?LeaveType $pool = null): ?array
    {
        $pool ??= $this->sources->earnedPool($employee, $this->onDate($year));
        if ($pool === null) {
            return null;
        }

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $pool->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere(fn ($w) => $w->whereNull('leave_year_id')->where('year', $year->legacyYear())))
            ->first();
        $summary = $balance ? $this->calculator->summary($balance) : null;
        $lines = self::lines($summary);
        // Of the days used: those the HR register stands for (restated by
        // leave:reconcile-hr-history), and those taken through the portal.
        $historical = $balance ? round(-(float) LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $pool->id)
            ->where('leave_year_id', $year->id)->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)
            ->where('source_type', LeaveHrHistoryReconciliationService::SOURCE_TYPE)->sum('days'), 2) : 0.0;

        $today = Carbon::today();
        $plan = $this->accrual->planFor($employee, $year, $today);
        $credited = $plan['credited_months'];
        $generated = round((float) $plan['base_days'] + count($credited) * EarnedLeaveAccrualService::MONTHLY_DAYS, 2);
        $room = max(0, (int) floor((EarnedLeaveAccrualService::ANNUAL_DAYS - $generated + self::EPSILON) / EarnedLeaveAccrualService::MONTHLY_DAYS));
        $judged = in_array($plan['status'], [EarnedLeaveAccrualService::READY, EarnedLeaveAccrualService::NO_CHANGE], true);
        // Months still to be credited this year: eligible, not yet credited, within the 12.
        $future = $judged ? array_slice(array_values(array_diff($plan['eligible_months'], $credited)), 0, $room) : [];
        $next = $future[0] ?? null;

        return [
            'leave_type_id' => $pool->id,
            'leave_type' => $pool->name,
            'has_balance' => $balance !== null,
            'year' => ['id' => $year->id, 'label' => $year->label, 'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString()],
        ] + $lines + [
            'historical_used' => $historical + 0.0,
            'portal_used' => round($lines['used'] - $historical, 2),
            'monthly_days' => EarnedLeaveAccrualService::MONTHLY_DAYS,
            'annual_entitlement' => EarnedLeaveAccrualService::ANNUAL_DAYS,
            'monthly_credits' => count($credited),
            'generated_this_year' => $generated,
            'entitlement_remaining' => round($room * EarnedLeaveAccrualService::MONTHLY_DAYS, 2),
            'starts_from' => $plan['transition'],
            'accrual_status' => $plan['status'],
            'accrual_reason' => $plan['reason'],
            'next_credit' => $next === null ? null : [
                'month' => $next,
                'date' => $this->months->monthEnd($next)->addDay()->toDateString(),
                'days' => EarnedLeaveAccrualService::MONTHLY_DAYS,
            ],
            // Year end, if no more leave is taken: today's total plus the
            // monthly credits still to come — never carry forward again, and
            // never beyond this year's 12. Unknown while accrual needs review.
            'forecast' => $judged ? [
                'future_credits' => round(count($future) * EarnedLeaveAccrualService::MONTHLY_DAYS, 2),
                'year_end_available' => round($lines['total_available'] + count($future) * EarnedLeaveAccrualService::MONTHLY_DAYS, 2),
            ] : null,
        ];
    }

    /** Today inside the year, else its nearest edge. */
    private function onDate(LeaveYear $year): Carbon
    {
        $today = Carbon::today();

        return $today->lt($year->starts_on) ? Carbon::parse($year->starts_on) : ($today->gt($year->ends_on) ? Carbon::parse($year->ends_on) : $today);
    }
}
