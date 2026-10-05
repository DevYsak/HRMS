<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The ONE answer to "is this a working day?" — for attendance, absence jobs,
 * leave day counting, payroll attendance, reports and dashboards alike.
 *
 * The company working week is configuration: AttendanceSetting's
 * weekly_off_days, which defaults to the Conexus rule — Saturday and Sunday
 * off (HR-confirmed). Nothing else may decide the weekly off on its own
 * (no isWeekend(), no hard-coded Sunday).
 *
 * For an employee and a date, classify() returns one state, in this order:
 *
 *   EMPLOYMENT_NOT_STARTED  before the joining date
 *   EMPLOYMENT_ENDED        after the last working day
 *   PUBLIC_HOLIDAY          a holiday on the employee's calendar
 *   MDL_SHUTDOWN            a Mandatory December Leave shutdown date
 *   WEEKLY_OFF              a configured weekly off (Saturday / Sunday)
 *   APPROVED_LEAVE          approved leave covering the date
 *   WORKING_DAY             otherwise
 *
 * A day that is both a holiday and a weekly off is one non-working day.
 * Attendance actually recorded on a non-working day is kept as it is and
 * shown as "Worked on Weekly Off" — it is never rejected, never turned into a
 * scheduled working day, and never paid as overtime without the OT approval.
 */
class WorkingDayResolver
{
    public const WORKING_DAY = 'working_day';

    public const WEEKLY_OFF = 'weekly_off';

    public const PUBLIC_HOLIDAY = 'public_holiday';

    public const MDL_SHUTDOWN = 'mdl_shutdown';

    public const APPROVED_LEAVE = 'approved_leave';

    public const EMPLOYMENT_NOT_STARTED = 'employment_not_started';

    public const EMPLOYMENT_ENDED = 'employment_ended';

    public const WEEKLY_OFF_LABEL = 'Weekly Off';

    public const WORKED_WEEKLY_OFF_LABEL = 'Worked on Weekly Off';

    public function __construct(private readonly HolidayResolver $holidays) {}

    /** The configured weekly off — company-wide (Saturday + Sunday by default). */
    public function isWeeklyOff(CarbonInterface $date): bool
    {
        return AttendanceSetting::isWeeklyOff($date);
    }

    /** @return array<int, int> Carbon dayOfWeek numbers (0 = Sunday … 6 = Saturday) */
    public function weeklyOffDays(): array
    {
        return AttendanceSetting::weeklyOffDays();
    }

    /** One state for this employee on this date (see the class note for the order). */
    public function classify(Employee $employee, CarbonInterface $date, bool $withLeave = true): string
    {
        $day = Carbon::parse($date)->startOfDay();

        if ($employee->joining_date && Carbon::parse($employee->joining_date)->startOfDay()->gt($day)) {
            return self::EMPLOYMENT_NOT_STARTED;
        }

        $lastDay = $employee->exitRecord?->last_working_day;
        if ($lastDay && Carbon::parse($lastDay)->startOfDay()->lt($day)) {
            return self::EMPLOYMENT_ENDED;
        }

        if ($this->holidays->isHoliday($employee, $day)) {
            return self::PUBLIC_HOLIDAY;
        }

        if (DecemberMandatoryDay::isMandatory($day)) {
            return self::MDL_SHUTDOWN;
        }

        if ($this->isWeeklyOff($day)) {
            return self::WEEKLY_OFF;
        }

        if ($withLeave && LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->exists()) {
            return self::APPROVED_LEAVE;
        }

        return self::WORKING_DAY;
    }

    /** Whether the employee is expected at work (leave counts as not expected). */
    public function isWorkingDay(Employee $employee, CarbonInterface $date): bool
    {
        return $this->classify($employee, $date) === self::WORKING_DAY;
    }

    /**
     * A scheduled working day for the company calendar, without an employee:
     * not a weekly off and not an MDL shutdown date. (Holidays depend on the
     * employee's calendar; pass an employee to classify() for those.)
     */
    public function isCompanyWorkingDay(CarbonInterface $date): bool
    {
        return ! $this->isWeeklyOff($date) && ! DecemberMandatoryDay::isMandatory($date);
    }

    /** Scheduled working days in an inclusive range, excluding weekly offs only. */
    public function weekdaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $count = 0;
        for ($cursor = Carbon::parse($from)->startOfDay(), $end = Carbon::parse($to)->startOfDay(); $cursor->lte($end); $cursor = $cursor->addDay()) {
            $count += $this->isWeeklyOff($cursor) ? 0 : 1;
        }

        return $count;
    }

    /**
     * The employee's scheduled working days in a range: weekly offs, their
     * holidays, MDL dates and days outside employment are excluded; leave is
     * not (leave is taken FROM scheduled days).
     */
    public function scheduledDaysBetween(Employee $employee, CarbonInterface $from, CarbonInterface $to): int
    {
        $count = 0;
        for ($cursor = Carbon::parse($from)->startOfDay(), $end = Carbon::parse($to)->startOfDay(); $cursor->lte($end); $cursor = $cursor->addDay()) {
            $count += $this->classify($employee, $cursor, withLeave: false) === self::WORKING_DAY ? 1 : 0;
        }

        return $count;
    }

    /**
     * The label a screen shows for a non-working day, or null on a working
     * day: "Weekly Off", or "Worked on Weekly Off" when attendance exists.
     */
    public function weeklyOffLabel(CarbonInterface $date, bool $worked): ?string
    {
        if (! $this->isWeeklyOff($date)) {
            return null;
        }

        return $worked ? self::WORKED_WEEKLY_OFF_LABEL : self::WEEKLY_OFF_LABEL;
    }
}
