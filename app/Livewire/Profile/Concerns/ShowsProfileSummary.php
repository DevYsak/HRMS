<?php

namespace App\Livewire\Profile\Concerns;

use App\Models\AttendanceDailyScore;
use App\Models\Employee;
use App\Services\Attendance\AttendanceRateService;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\Profile\ProfileCompletionService;

/**
 * The read-only half of a profile page — hero numbers and completion.
 *
 * Shared by the employee's own profile and the HR view, which differ only in
 * what they may *write*. Keeping the read side here means the two surfaces can
 * never drift into showing different numbers for the same person.
 */
trait ShowsProfileSummary
{
    /**
     * Five headline numbers, deliberately cheap aggregates — a profile shell
     * must not become the slowest page in the app.
     *
     * @return array{attendance:int|null, leave:float, comp_off:float, score:int|null, tenure:string}
     */
    protected function summaryKpis(Employee $employee): array
    {
        $from = now()->startOfMonth();

        // The one attendance % (QA-F-079), month to date.
        $attendance = app(AttendanceRateService::class)->forEmployee($employee, $from, now());

        // Leave comes from the same overview as My Time Off and the dashboard:
        // Available Leave = CSL + Comp Off still requestable. Summing every
        // balance row added Maternity/Paternity entitlements (e.g. 162 days).
        $leave = app(EmployeeLeaveOverviewService::class)->for($employee);

        // Read the engine's own daily scores rather than recomputing, so the
        // profile always agrees with the attendance module.
        $score = AttendanceDailyScore::where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), now()->toDateString()])
            ->avg('score');

        $tenure = $employee->joining_date?->diff(now());

        return [
            'attendance' => $attendance['percent'],
            'leave' => round((float) $leave['available_leave'], 1),
            'comp_off' => round((float) ($leave['comp_off']['earned'] ?? 0), 1),
            'score' => $score !== null ? (int) round((float) $score) : null,
            'tenure' => $tenure ? $tenure->y.'y '.$tenure->m.'m' : 'Not set',
        ];
    }

    /** @return array{percent:int, completed:int, total:int, missing:array<int, array<string,string>>} */
    protected function summaryCompletion(Employee $employee): array
    {
        return app(ProfileCompletionService::class)->for($employee);
    }
}
