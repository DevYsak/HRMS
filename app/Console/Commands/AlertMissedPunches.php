<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Attendance\MissedPunchAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Notifies missed punches from live attendance data — each mid-day missed
 * punch, the week's early warning, and going over the weekly allowance
 * (which flags the employee for HR review) — to the employee and HR.
 * Scheduled every 10 minutes, right behind the engine sync, over yesterday
 * and today. Safe to re-run: nobody is notified twice.
 */
#[Signature('attendance:missed-punches
    {--from= : First day to check (Y-m-d); defaults to yesterday}
    {--to= : Last day to check (Y-m-d); defaults to today}')]
#[Description('Notify missed punches, the weekly warning and the allowance (flagging the employee) to the employee and HR')]
class AlertMissedPunches extends Command
{
    public function handle(MissedPunchAlerts $alerts): int
    {
        $from = Carbon::parse($this->option('from') ?: Carbon::yesterday()->toDateString());
        $to = Carbon::parse($this->option('to') ?: Carbon::today()->toDateString());
        $totals = ['missed' => 0, 'notified' => 0, 'warned' => 0, 'flagged' => 0];

        Employee::query()->where('status', 'active')->whereHas('user')
            ->with(['user', 'manager', 'department'])
            ->chunkById(100, function ($employees) use ($alerts, $from, $to, &$totals): void {
                foreach ($employees as $employee) {
                    try {
                        foreach ($alerts->scan($employee, $from, $to) as $k => $v) {
                            $totals[$k] += $v;
                        }
                    } catch (\Throwable $e) {
                        report($e);   // one bad record never stops the rest
                    }
                }
            });

        $this->info("Missed punches {$from->toDateString()} → {$to->toDateString()}: {$totals['missed']} found, {$totals['notified']} notified, {$totals['warned']} weekly warnings, {$totals['flagged']} newly over the allowance.");

        return self::SUCCESS;
    }
}
