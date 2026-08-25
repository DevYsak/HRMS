<?php

namespace App\Livewire\Profile\Concerns;

use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveBalance;
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
     * @return array{attendance:int, leave:float, comp_off:float, score:int|null, tenure:string}
     */
    protected function summaryKpis(Employee $employee): array
    {
        $from = now()->startOfMonth();

        $present = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), now()->toDateString()])
            ->whereNotNull('check_in')
            ->count();

        $workingDays = max(1, AttendanceSetting::workingDaysBetween($from, now()));

        $balances = LeaveBalance::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('year', now()->year)
            ->get();

        // Read the engine's own daily scores rather than recomputing, so the
        // profile always agrees with the attendance module.
        $score = AttendanceDailyScore::where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), now()->toDateString()])
            ->avg('score');

        $tenure = $employee->joining_date?->diff(now());

        return [
            'attendance' => min(100, (int) round($present / $workingDays * 100)),
            'leave' => round($balances->sum(fn (LeaveBalance $b) => $b->available()), 1),
            'comp_off' => round(
                $balances->filter(fn (LeaveBalance $b) => $b->leaveType?->category === 'comp_off')
                    ->sum(fn (LeaveBalance $b) => (float) ($b->comp_off_credits ?? 0)),
                1
            ),
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
