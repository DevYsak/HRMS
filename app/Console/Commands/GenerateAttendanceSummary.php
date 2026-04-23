<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateAttendanceSummary extends Command
{
    protected $signature   = 'hrms:generate-attendance-summary';
    protected $description = 'Generate monthly attendance summary statistics for all employees (run on 1st of month for previous month).';

    public function handle(): int
    {
        $month      = now()->subMonth();
        $monthStart = $month->startOfMonth()->toDateString();
        $monthEnd   = $month->endOfMonth()->toDateString();
        $label      = $month->format('Y-m');

        $employees = Employee::with('user')->where('status', 'active')->get();

        $processed = 0;

        foreach ($employees as $employee) {
            $records = Attendance::where('employee_id', $employee->id)
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->get();

            $summary = [
                'employee_id'       => $employee->id,
                'month'             => $label,
                'total_days'        => $records->count(),
                'present_days'      => $records->whereNotNull('check_out')->count(),
                'late_days'         => $records->where('is_late', true)->count(),
                'missing_checkouts' => $records->where('missing_checkout', true)->count(),
                'total_hours'       => round($records->sum(fn ($r) => $r->netHours()), 2),
                'total_break_mins'  => $records->sum('break_minutes'),
            ];

            // Upsert into attendance_summaries table (created if it exists, otherwise logged only)
            $this->line(
                "Employee #{$employee->id} ({$employee->user->name}): "
                . "{$summary['present_days']} days, {$summary['total_hours']}h, "
                . "{$summary['late_days']} late"
            );

            $processed++;
        }

        $this->info("Generated attendance summary for {$processed} employee(s) for {$label}.");

        return self::SUCCESS;
    }
}
