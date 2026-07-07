<?php

namespace App\Services;

use App\Enums\AttendanceMode;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\BreakLog;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function checkIn(Employee $employee, ShiftSetting $shift, array $payload = []): Attendance
    {
        $now = Carbon::now();
        $shiftStart = Carbon::parse($shift->start_time, config('app.timezone'))
            ->setDate($now->year, $now->month, $now->day);
        $cutoff = $shiftStart->copy()->addMinutes((int) $shift->grace_minutes);

        $isLate = $now->gt($cutoff);
        $lateMinutes = $isLate ? (int) $cutoff->diffInMinutes($now) : 0;

        return Attendance::create([
            'employee_id' => $employee->id,
            'date' => $now->toDateString(),
            'check_in' => $now,
            'check_in_ip' => $payload['ip'] ?? request()->ip(),
            'check_in_lat' => $payload['lat'] ?? null,
            'check_in_lng' => $payload['lng'] ?? null,
            'check_in_photo' => $payload['photo'] ?? null,
            'check_in_user_agent' => $payload['user_agent'] ?? request()->userAgent(),
            'work_mode' => in_array($mode = $payload['work_mode'] ?? 'office', AttendanceMode::values(), true) ? $mode : 'office',
            'status' => $isLate ? 'late' : 'on_time',
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
        ]);
    }

    public function checkOut(Attendance $attendance, array $payload = []): Attendance
    {
        $now = Carbon::now();
        $totalHours = round($attendance->check_in->diffInMinutes($now) / 60, 2);

        $attendance->update([
            'check_out' => $now,
            'check_out_ip' => $payload['ip'] ?? request()->ip(),
            'check_out_lat' => $payload['lat'] ?? null,
            'check_out_lng' => $payload['lng'] ?? null,
            'check_out_photo' => $payload['photo'] ?? null,
            'check_out_user_agent' => $payload['user_agent'] ?? request()->userAgent(),
            'total_hours' => $totalHours,
        ]);

        $this->creditCompOffIfEligible($attendance->fresh(['employee.shift', 'employee.office']), $now);

        return $attendance->fresh();
    }

    public function startBreak(Attendance $attendance): ?BreakLog
    {
        if ($attendance->check_out || $attendance->activeBreak()->exists()) {
            return null;
        }

        return BreakLog::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'break_start' => Carbon::now(),
        ]);
    }

    public function endBreak(Attendance $attendance): ?BreakLog
    {
        $activeBreak = $attendance->activeBreak()->first();

        if (! $activeBreak) {
            return null;
        }

        $now = Carbon::now();
        $minutes = (int) $activeBreak->break_start->diffInMinutes($now);

        $activeBreak->update([
            'break_end' => $now,
            'duration_minutes' => $minutes,
        ]);

        $attendance->update([
            'break_minutes' => (int) $attendance->breakLogs()->whereNotNull('break_end')->sum('duration_minutes'),
        ]);

        return $activeBreak->fresh();
    }

    public function approveRegularisation(AttendanceRegularisation $regularisation, int $reviewerId, ?string $comment = null): Attendance
    {
        return DB::transaction(function () use ($regularisation, $reviewerId, $comment) {
            $regularisation->update([
                'status' => 'approved',
                'reviewer_id' => $reviewerId,
                'reviewer_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            // requested_check_in/out are TIME columns — anchor them to the work
            // date so the corrected punch/attendance lands on the right day (not
            // "today" when the regularisation is loaded fresh from the DB).
            $workDate = Carbon::parse($regularisation->work_date)->toDateString();
            $checkIn = Carbon::parse($workDate.' '.Carbon::parse($regularisation->requested_check_in)->format('H:i:s'));
            $checkOut = Carbon::parse($workDate.' '.Carbon::parse($regularisation->requested_check_out)->format('H:i:s'));

            // Seed check_in/out on create so regularising a fully-absent day
            // (no existing attendance row) doesn't violate the NOT NULL columns.
            $attendance = $regularisation->attendance
                ?? Attendance::firstOrCreate(
                    ['employee_id' => $regularisation->employee_id, 'date' => $regularisation->work_date],
                    ['check_in' => $checkIn, 'check_out' => $checkOut, 'status' => 'on_time', 'work_mode' => 'office'],
                );

            if (! $regularisation->attendance_id) {
                $regularisation->update(['attendance_id' => $attendance->id]);
            }

            $grossMinutes = $checkIn->diffInMinutes($checkOut);
            $netMinutes = max(0, $grossMinutes - ((int) $attendance->break_minutes));

            $attendance->update(array_filter([
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'check_in_method' => $regularisation->check_in_method,
                'check_out_method' => $regularisation->check_out_method,
                'total_hours' => round($netMinutes / 60, 2),
                'status' => 'on_time',
                'is_late' => false,
                'late_minutes' => 0,
                'missing_checkout' => false,
            ], fn ($v) => $v !== null));

            // Write the corrected in/out into the punch journey (with the
            // declared method) so the employee's Attendance Journey reflects the
            // approved fix — not just the summary times. Without this the journey
            // is built purely from biometric punches and the correction is
            // invisible to the employee even though the admin summary shows it.
            $this->writeRegularisedPunches($regularisation, $checkIn, $checkOut);

            // If the corrected day now exceeds the OT threshold, file the OT
            // request and auto-approve it under the same reviewer so overtime
            // flows straight to payroll — approving the regularisation approves
            // the overtime it produced (materialises the OvertimeRecord).
            $otRequest = app(OvertimeService::class)->autoCreateFromAttendance($attendance->fresh(['employee.shift']));
            if ($otRequest) {
                app(OvertimeService::class)->approve($otRequest, $reviewerId, 'Auto-approved with attendance regularisation.');
            }

            return $attendance->fresh();
        });
    }

    /**
     * Upsert the corrected check-in / check-out as journey punches so an
     * approved regularisation appears in the employee's Attendance Journey.
     * Keyed on (employee, punched_at) for idempotency and tagged
     * source='regularisation' so it is distinguishable from device punches.
     * Near-duplicate device punches (a few seconds off) are harmless — the
     * PunchClassifier collapses them when the journey is rendered.
     */
    private function writeRegularisedPunches(AttendanceRegularisation $regularisation, Carbon $checkIn, Carbon $checkOut): void
    {
        $employee = $regularisation->employee;
        $date = Carbon::parse($regularisation->work_date)->toDateString();

        foreach ([[$checkIn, $regularisation->check_in_method], [$checkOut, $regularisation->check_out_method]] as [$time, $method]) {
            AttendancePunch::updateOrCreate(
                ['employee_id' => $regularisation->employee_id, 'punched_at' => $time],
                [
                    'employee_code' => $employee?->employee_code,
                    'punch_date' => $date,
                    'method' => $method ?: 'id_card',
                    'verify_raw' => $method,
                    'source' => 'regularisation',
                ],
            );
        }
    }

    public function rejectRegularisation(AttendanceRegularisation $regularisation, int $reviewerId, string $comment): AttendanceRegularisation
    {
        $regularisation->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reviewer_comment' => $comment,
            'reviewed_at' => now(),
        ]);

        return $regularisation->fresh();
    }

    protected function creditCompOffIfEligible(Attendance $attendance, Carbon $date): void
    {
        $employee = $attendance->employee;

        if (! $employee) {
            return;
        }

        $isMandatoryDay = DecemberMandatoryDay::isMandatory($date);
        $isUkHoliday = $this->resolveHolidayCountry($employee) === 'UK'
            && PublicHoliday::isHoliday($date, 'UK');

        if (! $isMandatoryDay && ! $isUkHoliday) {
            return;
        }

        app(LeaveService::class)->creditCompOff($employee, $date);
    }

    protected function resolveHolidayCountry(Employee $employee): string
    {
        $officeCountry = strtoupper((string) ($employee->office?->country ?? ''));
        if (in_array($officeCountry, ['UK', 'UNITED KINGDOM', 'GB', 'GREAT BRITAIN'], true)) {
            return 'UK';
        }

        $shiftName = strtoupper((string) ($employee->shift?->name ?? ''));
        if (str_contains($shiftName, 'UK')) {
            return 'UK';
        }

        return 'IN';
    }
}
