<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\User;
use App\Models\WarningLetter;
use App\Services\Performance\WarningService;
use Illuminate\Console\Command;

/**
 * Rule 10 (HR side) — automatic disciplinary follow-through for late marks.
 *
 * When an employee's month-to-date late count reaches the configurable
 * threshold (attendance_settings.late_warning_threshold), issue a formal
 * warning letter through the existing WarningService, which also records the
 * performance-timeline event and notifies the employee. Repeat offences in a
 * later month escalate the chain (verbal → first written → final → PIP).
 * Idempotent: at most one attendance-late warning per employee per month.
 */
class IssueLateWarnings extends Command
{
    /** Reason marker used to recognise attendance-late warnings for idempotency/escalation. */
    private const REASON_PREFIX = 'Attendance discipline:';

    protected $signature = 'hrms:issue-late-warnings';

    protected $description = 'Issue/escalate warning letters for employees whose monthly late marks reached the configured threshold.';

    public function handle(WarningService $warnings): int
    {
        $threshold = max(1, (int) (AttendanceSetting::query()->value('late_warning_threshold') ?? 3));

        // System-issued letters need an issuing user for the audit trail.
        $issuer = User::where('role', UserRole::SuperAdmin)->orderBy('id')->first()
            ?? User::where('role', UserRole::HrAdmin)->orderBy('id')->first();
        if (! $issuer) {
            $this->warn('No super-admin/HR user found to issue system warnings — skipping.');

            return self::SUCCESS;
        }

        $monthStart = now()->startOfMonth()->toDateString();
        $monthLabel = now()->format('F Y');

        $lateCounts = Attendance::query()
            ->selectRaw('employee_id, COUNT(*) as late_count')
            ->whereBetween('date', [$monthStart, now()->toDateString()])
            ->where('is_late', true)
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->pluck('late_count', 'employee_id');

        $issued = 0;
        $escalated = 0;

        foreach ($lateCounts as $employeeId => $lateCount) {
            $employee = Employee::with('user')
                ->where('status', 'active')
                ->whereHas('user')
                ->find($employeeId);
            if (! $employee) {
                continue;
            }

            // One attendance-late warning per employee per month.
            $alreadyThisMonth = WarningLetter::where('employee_id', $employee->id)
                ->where('reason', 'like', self::REASON_PREFIX.'%')
                ->whereBetween('issue_date', [$monthStart, now()->toDateString()])
                ->exists();
            if ($alreadyThisMonth) {
                continue;
            }

            $reason = sprintf(
                '%s %d late arrivals in %s (threshold %d)',
                self::REASON_PREFIX, $lateCount, $monthLabel, $threshold,
            );

            // A repeat offence escalates the existing chain instead of piling
            // up parallel verbal warnings.
            $previous = WarningLetter::where('employee_id', $employee->id)
                ->where('reason', 'like', self::REASON_PREFIX.'%')
                ->orderByDesc('issue_date')
                ->first();

            if ($previous && $previous->nextWarningType() !== null) {
                $warnings->escalate($previous, $issuer, ['reason' => $reason]);
                $escalated++;
            } elseif ($previous) {
                // Chain exhausted (termination review) — nothing further to issue.
                continue;
            } else {
                $warnings->issue($employee, [
                    'warning_type' => 'verbal',
                    'reason' => $reason,
                    'description' => 'Automatically issued by the attendance engine: monthly late arrivals reached the configured threshold. Late marks also reduce the attendance and performance scores.',
                    'issue_date' => now()->toDateString(),
                    'next_review_date' => now()->addMonth()->startOfMonth()->toDateString(),
                ], $issuer);
                $issued++;
            }
        }

        $this->info("Late-mark warnings — issued: {$issued}, escalated: {$escalated} (threshold {$threshold}).");

        return self::SUCCESS;
    }
}
