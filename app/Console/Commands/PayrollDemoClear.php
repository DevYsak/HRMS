<?php

namespace App\Console\Commands;

use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipItem;
use Database\Seeders\DemoPayrollHistorySeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Removes the demo payroll history created by DemoPayrollHistorySeeder.
 *
 * Only payrolls tagged with DemoPayrollHistorySeeder::DEMO_TAG are removed, so
 * real payroll runs are never at risk. Requires --force on production, matching
 * how `db:seed` guards itself.
 */
#[Signature('payroll:demo-clear {--force : Run without confirmation}')]
#[Description('Delete demo payroll history seeded by DemoPayrollHistorySeeder (real payroll is untouched)')]
class PayrollDemoClear extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        $demoPayrolls = Payroll::where('finance_note', DemoPayrollHistorySeeder::DEMO_TAG)->get();

        if ($demoPayrolls->isEmpty()) {
            $this->info('No demo payroll found. Nothing to remove.');

            return self::SUCCESS;
        }

        $this->warn('The following DEMO payrolls will be deleted:');
        foreach ($demoPayrolls as $payroll) {
            $slips = $payroll->payslips()->count();
            $this->line("  - {$payroll->month} {$payroll->year} ({$slips} payslip(s))");
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $payrollIds = $demoPayrolls->pluck('id');
        $payslipIds = Payslip::whereIn('payroll_id', $payrollIds)->pluck('id');

        $items = PayslipItem::whereIn('payslip_id', $payslipIds)->delete();
        $slips = Payslip::whereIn('id', $payslipIds)->delete();
        $runs = Payroll::whereIn('id', $payrollIds)->delete();

        $this->info("Removed {$runs} demo payroll(s), {$slips} payslip(s), {$items} payslip line(s).");
        $this->line('Real payroll data was not touched.');

        return self::SUCCESS;
    }
}
