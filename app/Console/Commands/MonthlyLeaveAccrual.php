<?php

namespace App\Console\Commands;

use App\Services\LeaveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('hrms:monthly-leave-accrual {--year= : Target year (defaults to current)} {--month= : Target month 1-12 (defaults to current)}')]
#[Description('Credit monthly leave accruals for all eligible employees and leave types.')]
class MonthlyLeaveAccrual extends Command
{
    public function handle(LeaveService $service): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $month = (int) ($this->option('month') ?: now()->month);

        if ($month < 1 || $month > 12) {
            $this->error('Month must be between 1 and 12.');

            return self::FAILURE;
        }

        $monthName = Carbon::createFromDate($year, $month, 1)->format('F Y');
        $this->info("Processing leave accrual for {$monthName}...");

        $count = $service->accrueMonthly($year, $month);

        $this->info("Done — {$count} accrual credit(s) processed.");

        return self::SUCCESS;
    }
}
