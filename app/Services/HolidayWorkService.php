<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HolidayPaySetting;
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

        $settings = HolidayPaySetting::current();
        $location = $data['work_location'] ?? 'office';
        $payType = $data['pay_type'] ?? $settings->default_pay_type;

        if (! in_array($payType, HolidayPaySetting::ALL_PAY_TYPES, true)) {
            $payType = $settings->default_pay_type;
        }
        if (! $settings->isPayTypeAllowed($payType)) {
            $labels = HolidayPaySetting::payTypeLabels();
            throw new \DomainException(
                ($labels[$payType] ?? $payType).' is not an available pay type under the current holiday pay policy.'
            );
        }

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
            'pay_type' => $payType,
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

            // Materialise pay per the current Holiday Pay policy. On a holiday,
            // all worked hours are overtime; double_pay applies the configured
            // rate multiplier on top.
            $settings = HolidayPaySetting::current();

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
                $record = $this->overtime->approve($ot, $reviewerId, $comment ?: 'Auto-approved with holiday-work request.');

                // OvertimeService::calculateOtHours() treats a linked attendance
                // as "hours beyond a standard shift", which doesn't apply here —
                // on a holiday the entire worked duration is overtime. Recompute
                // ot_hours as the full requested hours, then apply the configured
                // OT rate override and/or double-pay multiplier.
                $otHours = round($hours, 2);
                $rate = $settings->ot_rate_per_hour !== null ? (float) $settings->ot_rate_per_hour : (float) $record->rate_per_hour;
                if ($request->pay_type === 'double_pay') {
                    $rate *= (float) $settings->double_pay_multiplier;
                }
                $record->update([
                    'ot_hours' => $otHours,
                    'rate_per_hour' => $rate,
                    'ot_amount' => round($otHours * $rate, 2),
                ]);
            } elseif ($request->pay_type === 'comp_off') {
                $this->leave->creditCompOff($employee, $date, (float) $settings->comp_off_days_per_holiday);
            } elseif ($request->pay_type === 'extra_leave') {
                $this->leave->creditCompOff($employee, $date, (float) $settings->extra_leave_days_per_holiday);
            } elseif ($request->pay_type === 'half_day') {
                $this->leave->creditCompOff($employee, $date, (float) $settings->half_day_comp_off_days);
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
