<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceMonthlySummary;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Spec §7 — on the 1st, summarise the previous month's attendance per
 * employee and keep it (attendance_monthly_summaries). Upserted on
 * (employee_id, month): re-running for the same month replaces, never
 * duplicates. --month=Y-m regenerates a specific month.
 */
class GenerateAttendanceSummary extends Command
{
    protected $signature = 'hrms:generate-attendance-summary {--month= : Month to summarise (Y-m); defaults to the previous month}';

    protected $description = 'Generate and store the monthly attendance summary for all current employees (run on 1st of month for previous month).';

    public function handle(): int
    {
        $month = $this->option('month')
            // '!' resets the unparsed fields: without it the day comes from
            // today, and on the 29th-31st '2026-09' overflowed into October.
            ? Carbon::createFromFormat('!Y-m', $this->option('month'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $label = $month->format('Y-m');

        $employees = Employee::with('user')
            ->whereNotIn('status', ['draft', 'inactive', 'archived'])
            ->get();

        $processed = 0;

        foreach ($employees as $employee) {
            try {
                $records = Attendance::where('employee_id', $employee->id)
                    ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->get();

                AttendanceMonthlySummary::updateOrCreate(
                    ['employee_id' => $employee->id, 'month' => $label],
                    [
                        'days_recorded' => $records->count(),
                        'present_days' => $records->whereNotNull('check_in')->count(),
                        'late_days' => $records->where('is_late', true)->count(),
                        'excess_break_days' => $records->where('excess_break_flag', true)->count(),
                        'missing_checkouts' => $records->where('missing_checkout', true)->count(),
                        'total_hours' => round($records->sum(fn ($r) => $r->netHours()), 2),
                        'total_break_minutes' => (int) $records->sum('break_minutes'),
                        'leave_days' => $this->leaveDays($employee, $monthStart, $monthEnd),
                        'generated_at' => now(),
                    ],
                );

                $processed++;
            } catch (\Throwable $e) {
                // One employee's bad data never stops the month's summary.
                report($e);
                $this->warn("Employee #{$employee->id}: {$e->getMessage()}");
            }
        }

        $this->info("Generated attendance summary for {$processed} employee(s) for {$label}.");

        return self::SUCCESS;
    }

    /** Approved leave days that fall inside the month (weekly offs excluded, half days as 0.5). */
    private function leaveDays(Employee $employee, CarbonInterface $monthStart, CarbonInterface $monthEnd): float
    {
        $days = 0.0;

        $requests = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $monthEnd)
            ->whereDate('end_date', '>=', $monthStart)
            ->get();

        foreach ($requests as $request) {
            if ($request->is_half_day) {
                $days += 0.5;

                continue;
            }

            $cursor = Carbon::parse($request->start_date)->max($monthStart);
            $last = Carbon::parse($request->end_date)->min($monthEnd);

            for (; $cursor->lte($last); $cursor = $cursor->copy()->addDay()) {
                if (! AttendanceSetting::isWeeklyOff($cursor)) {
                    $days += 1;
                }
            }
        }

        return round($days, 1);
    }
}
