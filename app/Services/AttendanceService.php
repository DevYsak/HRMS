<?php

namespace App\Services;

use App\Models\Attendance;
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
            'work_mode' => in_array($payload['work_mode'] ?? 'office', ['office', 'wfh'], true) ? $payload['work_mode'] : 'office',
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

            $attendance = $regularisation->attendance
                ?? Attendance::firstOrCreate([
                    'employee_id' => $regularisation->employee_id,
                    'date' => $regularisation->work_date,
                ]);

            if (! $regularisation->attendance_id) {
                $regularisation->update(['attendance_id' => $attendance->id]);
            }

            $checkIn = Carbon::parse($regularisation->requested_check_in);
            $checkOut = Carbon::parse($regularisation->requested_check_out);
            $grossMinutes = $checkIn->diffInMinutes($checkOut);
            $netMinutes = max(0, $grossMinutes - ((int) $attendance->break_minutes));

            $attendance->update([
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'total_hours' => round($netMinutes / 60, 2),
                'status' => 'on_time',
                'is_late' => false,
                'late_minutes' => 0,
                'missing_checkout' => false,
            ]);

            return $attendance->fresh();
        });
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
