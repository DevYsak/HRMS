<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\OtRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE attendance calculation (Pulse v3.1). Every writer of attendances.total_hours
 * and every screen, chart, export and report reads its figures from here so no
 * two places can disagree.
 *
 *  1. Worked = final clock-out − first clock-in. Breaks are NEVER deducted.
 *  2. Break time is informational. It is flagged as excess only above 60 minutes.
 *  3. Late = first clock-in after shift start + grace (minute precision):
 *     IT 10:30 + 5 → late from 10:36; UK 13:00 + 5 → late from 13:06.
 *     Never late on a weekly off, holiday or leave day.
 *  4. Overtime = max(worked − standard day, 0) ONLY when an approved OT request
 *     exists for the date; otherwise approved OT is 0 (worked hours still shown).
 *  5. Weekly offs, public holidays, MDL shutdown days and approved leave are
 *     never absent.
 *  6. A checkout is "missing" only once shift end + 1 hour has passed.
 *  7. A regularised day uses its corrected first-in / final-out; raw device
 *     punches recorded for that day do not change it.
 */
class AttendanceCalculator
{
    /** Policy: total break above this is excess (informational flag only). */
    public const EXCESS_BREAK_MINUTES = 60;

    /** Policy: a checkout is missing only after shift end + this. */
    public const MISSING_CHECKOUT_AFTER_MINUTES = 60;

    /** Standard day when the employee has no resolvable shift (Pulse v3.1: 9h). */
    public const DEFAULT_STANDARD_MINUTES = 540;

    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly WorkingDayResolver $workingDays,
        private readonly PunchTimeline $timeline,
    ) {}

    /** Whole minutes from first clock-in to final clock-out — the worked time. */
    public function spanMinutes(?CarbonInterface $firstIn, ?CarbonInterface $lastOut): int
    {
        if ($firstIn === null || $lastOut === null || $lastOut->lessThanOrEqualTo($firstIn)) {
            return 0;
        }

        return (int) floor($firstIn->diffInSeconds($lastOut, true) / 60);
    }

    /**
     * Hours to store in attendances.total_hours for a closed day: final
     * clock-out − first clock-in, rounded to 2dp. No break deduction.
     */
    public function storedHours(?CarbonInterface $checkIn, ?CarbonInterface $checkOut): float
    {
        return round($this->spanMinutes($checkIn, $checkOut) / 60, 2);
    }

    /** Worked hours of an attendance row (from its first-in / final-out). */
    public function workedHours(?Attendance $attendance): float
    {
        return $attendance ? $this->storedHours($attendance->check_in, $attendance->check_out) : 0.0;
    }

    /**
     * The full calculation for an attendance row. Punches are loaded for the
     * row's employee and date unless supplied.
     *
     * @param  Collection<int, AttendancePunch>|null  $punches
     */
    public function forAttendance(Attendance $attendance, ?Collection $punches = null, ?CarbonInterface $now = null, ?bool $hasApprovedOt = null): AttendanceDay
    {
        $employee = $attendance->relationLoaded('employee') ? $attendance->employee : $attendance->employee()->first();

        return $this->forDay($employee, $attendance->date, $attendance, $punches, $now, $hasApprovedOt);
    }

    /**
     * The full calculation for one employee on one day.
     *
     * @param  Collection<int, AttendancePunch>|null  $punches  null = load from attendance_punches
     */
    public function forDay(
        Employee $employee,
        CarbonInterface|string $date,
        ?Attendance $attendance = null,
        ?Collection $punches = null,
        ?CarbonInterface $now = null,
        ?bool $hasApprovedOt = null,
    ): AttendanceDay {
        $day = Carbon::parse($date instanceof CarbonInterface ? $date->toDateString() : $date)->startOfDay();
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $dayType = $this->workingDays->classify($employee, $day);
        $shift = $this->shifts->resolve($employee, $day);

        [$firstIn, $lastOut, $breakMinutes, $source] = $this->timing($employee, $day, $attendance, $punches, $now);

        $isToday = $day->isSameDay($now);
        $live = $firstIn !== null && $lastOut === null && $isToday;
        $missingCheckout = $firstIn !== null && $lastOut === null && $now->greaterThan($this->missingCheckoutDeadline($day, $shift));
        // Open and still inside the shift window: counting to now. Once the
        // checkout is missing nothing more is counted — never a phantom day.
        $worked = $lastOut !== null
            ? $this->spanMinutes($firstIn, $lastOut)
            : ($live && ! $missingCheckout ? $this->spanMinutes($firstIn, $now) : 0);

        $working = $dayType === WorkingDayResolver::WORKING_DAY;
        $isLate = $working && $shift && $firstIn ? $shift->isLate(Carbon::parse($firstIn)) : false;
        $lateMinutes = $isLate ? $shift->lateMinutes(Carbon::parse($firstIn)) : 0;

        $expected = $shift?->expectedMinutes() ?: self::DEFAULT_STANDARD_MINUTES;
        $otThreshold = $shift ? (int) round($shift->otThresholdHours * 60) : self::DEFAULT_STANDARD_MINUTES;
        $beyond = max(0, $worked - $expected);
        $hasApprovedOt ??= $firstIn !== null && $this->hasApprovedOt($employee, $day);
        $approvedOt = $hasApprovedOt ? max(0, $worked - ($otThreshold ?: $expected)) : 0;

        return new AttendanceDay(
            date: $day,
            dayType: $dayType,
            firstIn: $firstIn,
            lastOut: $lastOut,
            live: $live && ! $missingCheckout,
            workedMinutes: $worked,
            breakMinutes: $breakMinutes,
            excessBreak: $breakMinutes > self::EXCESS_BREAK_MINUTES,
            isLate: $isLate,
            lateMinutes: $lateMinutes,
            expectedMinutes: $expected,
            beyondShiftMinutes: $beyond,
            hasApprovedOt: $hasApprovedOt,
            approvedOtMinutes: $approvedOt,
            missingCheckout: $missingCheckout,
            regularised: $source === 'regularised',
            source: $source,
            status: $this->status($dayType, $day, $now, $shift, $firstIn, $lastOut, $isLate, $missingCheckout),
            shift: $shift,
        );
    }

    /** The instant after which an unclosed day counts as a missing checkout. */
    public function missingCheckoutDeadline(CarbonInterface $day, ?ResolvedShift $shift): Carbon
    {
        return $shift
            ? $shift->end->copy()->addMinutes(self::MISSING_CHECKOUT_AFTER_MINUTES)
            : Carbon::parse($day->toDateString())->endOfDay();
    }

    public function hasApprovedOt(Employee $employee, CarbonInterface $day): bool
    {
        return OtRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('work_date', $day->toDateString())
            ->exists();
    }

    /**
     * First-in, final-out and informational break minutes for the day.
     *
     * @return array{0: ?Carbon, 1: ?Carbon, 2: int, 3: string}
     */
    private function timing(Employee $employee, Carbon $day, ?Attendance $attendance, ?Collection $punches, Carbon $now): array
    {
        // A regularised day is what HR approved — raw device punches recorded
        // later for the same date never change it.
        if ($attendance?->hasCorrectedPunches()) {
            return [
                $attendance->check_in ? Carbon::parse($attendance->check_in) : null,
                $attendance->check_out ? Carbon::parse($attendance->check_out) : null,
                (int) ($attendance->break_minutes ?? 0),
                'regularised',
            ];
        }

        $punches ??= AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', $day->toDateString())
            ->orderBy('punched_at')
            ->get();

        if ($punches->isNotEmpty()) {
            $t = $this->timeline->process($punches, $day);

            if ($t['first_in_at'] !== null) {
                $firstIn = $t['first_in_at'];
                $lastOut = $t['last_out_at'];

                // An open trailing IN on a past day that the row closed (auto
                // punch-out, a web checkout) keeps the row's checkout.
                if ($lastOut === null && $attendance?->check_out && ! $day->isSameDay($now)
                    && Carbon::parse($attendance->check_out)->greaterThan($firstIn)) {
                    $lastOut = Carbon::parse($attendance->check_out);
                }

                return [$firstIn, $lastOut, (int) $t['break_minutes'], 'punches'];
            }
        }

        if ($attendance?->check_in) {
            $breaks = (int) ($attendance->break_minutes ?? 0);
            if ($breaks === 0 && $attendance->relationLoaded('breakLogs')) {
                $breaks = (int) $attendance->breakLogs->sum('duration_minutes');
            }

            return [
                Carbon::parse($attendance->check_in),
                $attendance->check_out ? Carbon::parse($attendance->check_out) : null,
                $breaks,
                'attendance',
            ];
        }

        return [null, null, 0, 'none'];
    }

    private function status(string $dayType, Carbon $day, Carbon $now, ?ResolvedShift $shift, ?Carbon $firstIn, ?Carbon $lastOut, bool $isLate, bool $missingCheckout): string
    {
        if ($firstIn !== null) {
            return match (true) {
                $missingCheckout => AttendanceDay::STATUS_MISSING_CHECKOUT,
                $lastOut === null => AttendanceDay::STATUS_WORKING,
                $isLate => AttendanceDay::STATUS_LATE,
                default => AttendanceDay::STATUS_PRESENT,
            };
        }

        return match ($dayType) {
            WorkingDayResolver::WEEKLY_OFF => AttendanceDay::STATUS_WEEKLY_OFF,
            WorkingDayResolver::PUBLIC_HOLIDAY, WorkingDayResolver::MDL_SHUTDOWN => AttendanceDay::STATUS_HOLIDAY,
            WorkingDayResolver::APPROVED_LEAVE => AttendanceDay::STATUS_LEAVE,
            WorkingDayResolver::EMPLOYMENT_NOT_STARTED, WorkingDayResolver::EMPLOYMENT_ENDED => AttendanceDay::STATUS_NOT_EMPLOYED,
            default => match (true) {
                $day->greaterThan($now->copy()->startOfDay()) => AttendanceDay::STATUS_UPCOMING,
                // Today is not an absence until the shift is over.
                $day->isSameDay($now) && $now->lessThan($shift?->end ?? $day->copy()->endOfDay()) => AttendanceDay::STATUS_NOT_CHECKED_IN,
                default => AttendanceDay::STATUS_ABSENT,
            },
        };
    }
}
