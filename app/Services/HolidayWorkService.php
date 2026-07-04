<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HolidayWorkRequest;
use App\Models\OtRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Notifications\HolidayWorkRequestNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Work on Holiday" workflow. Submission validates the date really is a
 * holiday for the employee; approval materialises a holiday-worked
 * attendance plus the chosen pay (overtime record or comp-off credit),
 * reusing OvertimeService / LeaveService so payroll and balances stay
 * consistent with the rest of the system.
 */
class HolidayWorkService
{
    public function __construct(
        private OvertimeService $overtime,
        private LeaveService $leave,
    ) {}

    /**
     * @param  array{work_date:string, reason:string, work_location?:string, expected_hours?:float, project?:?string, manager_id?:?int, comments?:?string, attachment_path?:?string, pay_type?:string}  $data
     */
    public function submit(Employee $employee, array $data): HolidayWorkRequest
    {
        $date = Carbon::parse($data['work_date']);

        $holiday = PublicHoliday::holidayForEmployeeOn($date, $employee);
        if (! $holiday) {
            throw new \DomainException('The selected date is not a company holiday for you.');
        }

        $exists = HolidayWorkRequest::where('employee_id', $employee->id)
            ->where('work_date', $date->toDateString())
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
        if ($exists) {
            throw new \DomainException('You already have a holiday-work request for this date.');
        }

        $location = $data['work_location'] ?? 'office';
        $payType = $data['pay_type'] ?? 'overtime';

        $request = HolidayWorkRequest::create([
            'employee_id' => $employee->id,
            'holiday_id' => $holiday->id,
            'work_date' => $date->toDateString(),
            'reason' => $data['reason'],
            'work_location' => in_array($location, ['office', 'wfh', 'client_site'], true) ? $location : 'office',
            'expected_hours' => max(0.5, min(24, (float) ($data['expected_hours'] ?? 8))),
            'project' => $data['project'] ?? null,
            'manager_id' => $data['manager_id'] ?? $employee->manager_id,
            'comments' => $data['comments'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
            'pay_type' => in_array($payType, ['overtime', 'comp_off', 'double_pay', 'extra_leave', 'half_day'], true) ? $payType : 'overtime',
            'status' => 'pending',
        ]);

        // Notify the manager AND HR/Admin (queued — never blocks the request).
        $approvers = User::whereIn('role', ['hr_admin', 'super_admin'])->get();
        if ($employee->manager) {
            $approvers->push($employee->manager);
        }
        $approvers->unique('id')->each(fn ($u) => $u->notify(new HolidayWorkRequestNotification($request)));

        return $request;
    }

    /**
     * Approve a holiday-work request: create the holiday-worked attendance and
     * materialise the chosen pay. Returns the created/updated attendance.
     */
    public function approve(HolidayWorkRequest $request, int $reviewerId, ?string $comment = null): Attendance
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only pending holiday-work requests can be approved.');
        }

        return DB::transaction(function () use ($request, $reviewerId, $comment) {
            $employee = $request->employee;
            $date = Carbon::parse($request->work_date);
            $hours = (float) $request->expected_hours;

            // Nominal work window from the shift start (fallback 09:00).
            $start = $employee->shift?->start_time
                ? $date->copy()->setTimeFromTimeString(Carbon::parse($employee->shift->start_time)->format('H:i:s'))
                : $date->copy()->setTime(9, 0);
            $end = $start->copy()->addMinutes((int) round($hours * 60));

            $workMode = match ($request->work_location) {
                'wfh' => 'wfh',
                'client_site' => 'client_visit',
                default => 'office',
            };

            $attendance = Attendance::updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $date->toDateString()],
                [
                    'check_in' => $start,
                    'check_out' => $end,
                    'total_hours' => round($hours, 2),
                    'status' => 'holiday_worked',
                    'is_late' => false,
                    'late_minutes' => 0,
                    'missing_checkout' => false,
                    'work_mode' => $workMode,
                ],
            );

            // Materialise pay. On a holiday, all worked hours are overtime.
            if (in_array($request->pay_type, ['overtime', 'double_pay'], true)) {
                $ot = OtRequest::create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $attendance->id,
                    'work_date' => $date->toDateString(),
                    'start_time' => $start->format('H:i'),
                    'end_time' => $end->format('H:i'),
                    'requested_hours' => round($hours, 2),
                    'reason' => 'Holiday worked ('.$request->payTypeLabel().'): '.$request->reason,
                    'status' => 'pending',
                    'source' => 'holiday',
                ]);
                $this->overtime->approve($ot, $reviewerId, $comment ?: 'Auto-approved with holiday-work request.');
            } elseif ($request->pay_type === 'comp_off') {
                $this->leave->creditCompOff($employee, $date, $request->work_location === 'client_site' ? 1.0 : 1.0);
            } elseif ($request->pay_type === 'extra_leave') {
                $this->leave->creditCompOff($employee, $date, 1.0);
            }

            $request->update([
                'status' => 'approved',
                'reviewer_id' => $reviewerId,
                'reviewer_comment' => $comment,
                'reviewed_at' => now(),
                'attendance_id' => $attendance->id,
            ]);

            AuditLog::record($attendance, 'holiday_worked', null, $attendance->toArray());

            $request->employee->user?->notify(new HolidayWorkRequestNotification($request->fresh()));

            return $attendance;
        });
    }

    public function reject(HolidayWorkRequest $request, int $reviewerId, string $comment): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only pending holiday-work requests can be rejected.');
        }

        $request->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reviewer_comment' => $comment,
            'reviewed_at' => now(),
        ]);

        $request->employee->user?->notify(new HolidayWorkRequestNotification($request->fresh()));
    }

    public function cancel(HolidayWorkRequest $request): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only pending holiday-work requests can be cancelled.');
        }

        $request->update(['status' => 'cancelled']);
    }
}
