<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipDayAdjustment;
use Illuminate\Support\Carbon;

/**
 * Late corrections to closed payslips (Phase 4 policy). A finalised payslip
 * is never recalculated; when the unpaid days of its period change later —
 * leave approved, cancelled or re-classified, an absence regularised, an
 * exit date recorded — the difference is paid back (refund) or recovered
 * (arrear) in the employee's next open run, at that payslip's own day rate.
 *
 * Idempotent: each delta is measured against the payslip's snapshot plus
 * every adjustment other runs already applied, and a draft re-run replaces
 * its own rows instead of adding to them.
 */
class PayslipDayAdjustmentService
{
    /** How far back a closed payslip is still corrected. */
    private const LOOKBACK_MONTHS = 12;

    public function __construct(private readonly PayableDaysService $payableDays) {}

    /**
     * The adjustment lines for this run, with the ledger rows to persist.
     *
     * @return array{items: array<int, array{name: string, amount: float, type: string}>, rows: array<int, array<string, mixed>>, total: float}
     */
    public function forRun(Employee $employee, Payroll $payroll, Carbon $cycleStart): array
    {
        $sources = Payslip::where('employee_id', $employee->id)
            ->whereNotNull('payable_snapshot')
            ->where('payroll_id', '!=', $payroll->id)
            ->whereHas('payroll', fn ($q) => $q->where('status', 'finalized'))
            ->get()
            ->filter(function (Payslip $p) use ($cycleStart) {
                $to = Carbon::parse($p->payable_snapshot['to'] ?? '1970-01-01');

                return $to->lt($cycleStart) && $to->gte($cycleStart->copy()->subMonths(self::LOOKBACK_MONTHS));
            })
            ->sortBy(fn (Payslip $p) => $p->payable_snapshot['from']);

        $items = [];
        $rows = [];
        $total = 0.0;

        foreach ($sources as $source) {
            $snap = $source->payable_snapshot;
            $rate = (float) ($snap['daily_rate'] ?? 0);
            if ($rate <= 0) {
                continue;
            }

            $now = $this->payableDays->forPeriod($employee, Carbon::parse($snap['from']), Carbon::parse($snap['to']),
                isset($snap['lop_from']) ? Carbon::parse($snap['lop_from']) : null, Carbon::parse($snap['to']));
            $unpaidNow = $now['lop_days'] + $now['not_employed_working_days'];
            $unpaidThen = (float) $snap['lop_days'] + (float) $snap['not_employed_working_days'];
            $applied = (float) PayslipDayAdjustment::where('source_payslip_id', $source->id)
                ->where('payroll_id', '!=', $payroll->id)->sum('days');

            $delta = round($unpaidNow - $unpaidThen - $applied, 2);
            if (abs($delta) < 0.01) {
                continue;
            }

            $amount = round(abs($delta) * $rate, 2);
            $period = Carbon::parse($snap['from'])->format('d M').' – '.Carbon::parse($snap['to'])->format('d M Y');
            $days = rtrim(rtrim(number_format(abs($delta), 2, '.', ''), '0'), '.');
            $reason = $delta > 0
                ? "LWP adjustment — {$period} ({$days} ".(abs($delta) == 1.0 ? 'day' : 'days').')'
                : "LWP refund — {$period} ({$days} ".(abs($delta) == 1.0 ? 'day' : 'days').')';

            $items[] = ['name' => $reason, 'amount' => $amount, 'type' => $delta > 0 ? 'deduction' : 'earning'];
            $rows[] = ['employee_id' => $employee->id, 'source_payslip_id' => $source->id, 'payroll_id' => $payroll->id,
                'days' => $delta, 'amount' => $delta > 0 ? $amount : -$amount, 'reason' => $reason];
            $total += $delta > 0 ? -$amount : $amount;
        }

        return ['items' => $items, 'rows' => $rows, 'total' => round($total, 2)];
    }

    /**
     * Replace this run's ledger rows for the employee with the ones just
     * calculated, linked to the new payslip.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function persist(Employee $employee, Payroll $payroll, Payslip $payslip, array $rows): void
    {
        PayslipDayAdjustment::where('employee_id', $employee->id)->where('payroll_id', $payroll->id)->delete();

        foreach ($rows as $row) {
            PayslipDayAdjustment::create($row + ['applied_payslip_id' => $payslip->id]);
        }
    }
}
