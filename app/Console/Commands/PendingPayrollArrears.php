<?php

namespace App\Console\Commands;

use App\Models\Incentive;
use App\Models\LeaveEncashment;
use App\Models\OvertimeRecord;
use App\Models\Reimbursement;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Read-only: approved money not yet in any payroll run whose own period has
 * passed (D8). The next open run for the employee's cycle pays these as
 * arrears — this lets Finance verify them first. Nothing is changed.
 */
class PendingPayrollArrears extends Command
{
    protected $signature = 'payroll:pending-arrears {--before= : Only items from periods before this month (Y-m, default: the current month)}';

    protected $description = 'List approved payroll items (reimbursements, OT, incentives, encashments) waiting to be carried into the next open run';

    public function handle(): int
    {
        $before = (string) ($this->option('before') ?: now()->format('Y-m'));

        if (! preg_match('/^\d{4}-\d{2}$/', $before)) {
            $this->error('--before must be a month as Y-m, e.g. 2026-10.');

            return self::FAILURE;
        }

        $rows = collect();

        foreach ([Incentive::class => 'Incentive', Reimbursement::class => 'Reimbursement'] as $model => $kind) {
            $model::with('employee.user')
                ->where('status', 'approved')->whereNull('payroll_id')->where('month', '<', $before)
                ->get()
                ->each(fn ($item) => $rows->push([$kind, $item->employee?->user?->name, $item->month, $item->approved_at?->toDateString(), number_format((float) $item->amount, 2)]));
        }

        LeaveEncashment::with('employee.user')
            ->where('status', 'approved')->whereNull('payroll_id')->where('payout_month', '<', $before)
            ->get()
            ->each(fn ($item) => $rows->push(['Encashment', $item->employee?->user?->name, $item->payout_month, $item->reviewed_at?->toDateString(), $item->requested_days.' day(s)']));

        OvertimeRecord::with(['employee.user', 'otRequest'])
            ->unpaid()->whereNull('payslip_id')
            ->whereDate('work_date', '<', Carbon::parse($before.'-01')->toDateString())
            ->whereHas('otRequest', fn ($q) => $q->where('status', 'approved'))
            ->get()
            ->each(fn ($item) => $rows->push(['Overtime', $item->employee?->user?->name, $item->work_date->format('Y-m'), $item->otRequest?->reviewed_at?->toDateString(), number_format((float) $item->ot_amount, 2)]));

        if ($rows->isEmpty()) {
            $this->info("Nothing approved and unpaid from before {$before}.");

            return self::SUCCESS;
        }

        $this->table(['Type', 'Employee', 'Source period', 'Approved', 'Amount'], $rows->sortBy(fn ($r) => $r[2])->values()->all());
        $this->line($rows->count().' item(s) will be paid as arrears by each employee\'s next open payroll run.');

        return self::SUCCESS;
    }
}
