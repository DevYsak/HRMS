<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Attendance\AttendanceScoreEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly Attendance Score run (Rule 11): score every active employee's
 * previous day (or an explicit range for backfills). Idempotent — rescoring a
 * day replaces its row and breakdown.
 */
class ComputeAttendanceScores extends Command
{
    protected $signature = 'hrms:compute-attendance-scores
        {--date= : Score a single date (Y-m-d), defaults to yesterday}
        {--from= : Backfill start date (Y-m-d)}
        {--to= : Backfill end date (Y-m-d), defaults to yesterday}';

    protected $description = 'Compute per-day attendance scores (0–100 with audit breakdown) for all active employees.';

    public function handle(AttendanceScoreEngine $engine): int
    {
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : Carbon::parse($this->option('date') ?? Carbon::yesterday()->toDateString());
        $to = $this->option('from')
            ? Carbon::parse($this->option('to') ?? Carbon::yesterday()->toDateString())
            : $from->copy();

        $employees = Employee::query()
            ->where('status', 'active')
            ->whereHas('user')
            ->get();

        $scored = 0;
        foreach ($employees as $employee) {
            $scored += $engine->scoreRange($employee, $from, $to);
        }

        $this->info("Scored {$scored} employee-day(s) for {$employees->count()} employee(s) ({$from->toDateString()} → {$to->toDateString()}).");

        return self::SUCCESS;
    }
}
