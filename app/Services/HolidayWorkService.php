<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HolidayPaySetting;
use App\Models\HolidayWorkRequest;
use App\Models\OtRequest;
use App\Models\PublicHoliday;
use App\Notifications\HolidayWorkRequestNotification;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Notifications\NotificationRecipients;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Work on Holiday" workflow. Submission validates the date really is a
 * holiday for the employee; approval authorises the work. The chosen pay
 * (overtime record or comp-off credit) is settled from GENUINE attendance —
 * real punches through the canonical calculator — reusing OvertimeService /
 * LeaveService so payroll and balances stay consistent. No attendance, punch
 * time or hours are ever invented from expected or scheduled hours.
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

        // The manager plus HR as a queue — HR approves holiday work centrally,
        // so this broadcast is deliberate rather than a missing owner.
        $approvers = app(NotificationRecipients::class)->hrQueue();
        if ($employee->manager) {
            $approvers->push($employee->manager);
        }
        $manager = $employee->manager;
        $approvers->unique('id')->each(fn ($u) => $u->notify(
            (new HolidayWorkRequestNotification($request))->forRole($manager && $u->id === $manager->id ? 'manager' : 'hr_admin')
        ));

        return $request;
    }

    /**
     * Approve a holiday-work request. Approval AUTHORISES the work — it never
     * creates attendance, punch times or hours. Pay is settled by
     * {@see settle()} once the day has genuine attendance: a valid Face IN and
     * a final ID Card OUT, with the actual worked duration from the canonical
     * calculator. Returns the attendance when the request was settled now,
     * null while it still waits for real punches (or a real OUT).
     */
    public function approve(HolidayWorkRequest $request, int $reviewerId, ?string $comment = null): ?Attendance
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only pending holiday-work requests can be approved.');
        }

        app(ApprovalGuard::class)->assertCanDecide($reviewerId, $request->employee);

        $request->update([
            'status' => 'approved',
            'reviewer_id' => $reviewerId,
            'reviewer_comment' => $comment,
            'reviewed_at' => now(),
        ]);

        $request->employee->user?->notify((new HolidayWorkRequestNotification($request->fresh()))->forRole('employee'));

        return $this->settle($request->fresh());
    }

    /**
     * Settle every approved, unsettled request an employee has on a date —
     * called whenever the day's attendance may have just become genuine and
     * complete (a device sync, a clock-out, an approved regularisation).
     */
    public function settleForDay(Employee $employee, CarbonInterface $date): void
    {
        HolidayWorkRequest::where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->where('status', 'approved')
            ->whereNull('settled_at')
            ->get()
            ->each(fn (HolidayWorkRequest $request) => $this->settle($request));
    }

    /**
     * Pay an approved request from what actually happened. Needs real
     * attendance with a valid first IN and a valid final OUT; the worked
     * duration is the canonical one (final OUT − first IN, breaks not
     * deducted) — never the expected hours, the shift, or a default. With no
     * punches, or a missing checkout, nothing is paid and nothing is invented.
     */
    public function settle(HolidayWorkRequest $request): ?Attendance
    {
        if ($request->status !== 'approved' || $request->settled_at !== null || ! $request->employee) {
            return null;
        }

        $employee = $request->employee;
        $date = Carbon::parse($request->work_date);

        $attendance = Attendance::where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();
        if (! $attendance) {
            return null;
        }

        $day = app(AttendanceCalculator::class)->forDay($employee, $date, $attendance);
        if ($day->firstIn === null || $day->lastOut === null || $day->workedMinutes <= 0) {
            return null;   // no genuine IN → OUT yet: unresolved, regularise to resolve
        }

        $hours = round($day->workedMinutes / 60, 2);

        return DB::transaction(function () use ($request, $employee, $date, $attendance, $day, $hours) {
            $workMode = match ($request->work_location) {
                'wfh' => 'wfh',
                'client_site' => 'client_visit',
                default => 'office',
            };

            // Label the real day — its punch times and hours are never touched.
            if (in_array($attendance->status, ['on_time', 'late'], true)) {
                $attendance->update(['status' => 'holiday_worked', 'work_mode' => $workMode]);
            }

            $settings = HolidayPaySetting::current();

            if (in_array($request->pay_type, ['overtime', 'double_pay'], true)) {
                $ot = OtRequest::firstOrCreate(
                    ['employee_id' => $employee->id, 'work_date' => $date->toDateString(), 'source' => 'holiday'],
                    [
                        'attendance_id' => $attendance->id,
                        'start_time' => Carbon::parse($day->firstIn)->format('H:i'),
                        'end_time' => Carbon::parse($day->lastOut)->format('H:i'),
                        'requested_hours' => $hours,
                        'reason' => 'Holiday worked ('.$request->payTypeLabel().'): '.$request->reason,
                        'status' => 'pending',
                    ],
                );

                $record = $ot->status === 'pending'
                    ? $this->overtime->approve($ot, (int) $request->reviewer_id, $request->reviewer_comment ?: 'Approved with holiday-work request.')
                    : $ot->overtimeRecord;

                // On a holiday the ENTIRE actual duration is overtime; double_pay
                // applies the configured multiplier on top.
                $rate = $settings->ot_rate_per_hour !== null ? (float) $settings->ot_rate_per_hour : (float) $record->rate_per_hour;
                if ($request->pay_type === 'double_pay') {
                    $rate *= (float) $settings->double_pay_multiplier;
                }
                $record->update([
                    'ot_hours' => $hours,
                    'rate_per_hour' => $rate,
                    'ot_amount' => round($hours * $rate, 2),
                    'total_hours_worked' => $hours,
                ]);
            } elseif ($request->pay_type === 'comp_off') {
                $this->leave->creditCompOff($employee, $date, (float) $settings->comp_off_days_per_holiday);
            } elseif ($request->pay_type === 'extra_leave') {
                $this->leave->creditCompOff($employee, $date, (float) $settings->extra_leave_days_per_holiday);
            } elseif ($request->pay_type === 'half_day') {
                $this->leave->creditCompOff($employee, $date, (float) $settings->half_day_comp_off_days);
            }

            $request->update(['attendance_id' => $attendance->id, 'settled_at' => now(), 'actual_hours' => $hours]);

            AuditLog::record($attendance, 'holiday_worked', null, ['actual_hours' => $hours, 'pay_type' => $request->pay_type]);

            return $attendance->fresh();
        });
    }

    public function reject(HolidayWorkRequest $request, int $reviewerId, string $comment): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only pending holiday-work requests can be rejected.');
        }

        app(ApprovalGuard::class)->assertCanDecide($reviewerId, $request->employee);

        $request->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reviewer_comment' => $comment,
            'reviewed_at' => now(),
        ]);

        $request->employee->user?->notify((new HolidayWorkRequestNotification($request->fresh()))->forRole('employee'));
    }

    public function cancel(HolidayWorkRequest $request): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only pending holiday-work requests can be cancelled.');
        }

        $request->update(['status' => 'cancelled']);
    }
}
