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

    /**
     * MDL shutdown dates per year (Y-m-d => true), loaded once for this
     * resolver's lifetime (one calculation) instead of a query per day.
     *
     * @var array<int, array<string, true>>
     */
    private array $mandatoryDays = [];

    /**
     * Approved leave already looked up for one date and a known set of
     * employees (primeApprovedLeave), so a team's statuses need one query
     * rather than one per person.
     *
     * @var array{date: string, ids: array<int, true>, known: array<int, true>}|null
     */
    private ?array $primedLeave = null;

    public function __construct(private readonly HolidayResolver $holidays) {}

    /**
     * Look up approved leave on one date for many employees at once; classify()
     * then answers for them from memory.
     *
     * @param  array<int, int>  $employeeIds
     */
    public function primeApprovedLeave(array $employeeIds, CarbonInterface $date): self
    {
        $day = Carbon::parse($date)->toDateString();

        $this->primedLeave = [
            'date' => $day,
            'ids' => LeaveRequest::whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->where('start_date', '<=', $day)
                ->where('end_date', '>=', $day)
                ->distinct()->pluck('employee_id')
                ->mapWithKeys(fn ($id) => [(int) $id => true])->all(),
            'known' => array_fill_keys(array_map('intval', $employeeIds), true),
        ];

        return $this;
    }

    /** Whether the date is a December mandatory-leave (MDL) shutdown day. */
    public function isMandatoryDay(CarbonInterface $date): bool
    {
        $year = (int) $date->year;

        $this->mandatoryDays[$year] ??= DecemberMandatoryDay::where('year', $year)->pluck('date')
            ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])
            ->all();

        return isset($this->mandatoryDays[$year][Carbon::parse($date)->toDateString()]);
    }

    /** The configured weekly off — company-wide (Saturday + Sunday by default). */
    private function onApprovedLeave(Employee $employee, Carbon $day): bool
    {
        $primed = $this->primedLeave;

        if ($primed !== null && $primed['date'] === $day->toDateString() && isset($primed['known'][(int) $employee->id])) {
            return isset($primed['ids'][(int) $employee->id]);
        }

        return LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->exists();
    }

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

        if ($this->isMandatoryDay($day)) {
            return self::MDL_SHUTDOWN;
        }

        if ($this->isWeeklyOff($day)) {
            return self::WEEKLY_OFF;
        }

        if ($withLeave && $this->onApprovedLeave($employee, $day)) {
            return self::APPROVED_LEAVE;
        }

        return self::WORKING_DAY;
    }

    /**
     * classify() for every day of a range at once — the same states in the
     * same order, with the holidays, MDL dates and approved leave looked up
     * once for the whole range instead of once per day. For approved leave,
     * leave_days is 0.5 on a half day and 1 otherwise; it is 0 for every
     * other state.
     *
     * @return array<string, array{state: string, leave_days: float}> keyed by Y-m-d
     */
    public function classifyRange(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lt($start)) {
            return [];
        }

        $holidays = $this->holidays->keyedForEmployee($employee, $start, $end);
        $mdl = DecemberMandatoryDay::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $leaves = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get(['start_date', 'end_date', 'is_half_day']);

        $joining = $employee->joining_date ? Carbon::parse($employee->joining_date)->startOfDay() : null;
        $lastDay = $employee->exitRecord?->last_working_day ? Carbon::parse($employee->exitRecord->last_working_day)->startOfDay() : null;

        $days = [];
        for ($day = $start->copy(); $day->lte($end); $day = $day->copy()->addDay()) {
            $key = $day->toDateString();
            $leave = null;

            $state = match (true) {
                $joining !== null && $joining->gt($day) => self::EMPLOYMENT_NOT_STARTED,
                $lastDay !== null && $lastDay->lt($day) => self::EMPLOYMENT_ENDED,
                $holidays->has($key) => self::PUBLIC_HOLIDAY,
                $mdl->has($key) => self::MDL_SHUTDOWN,
                $this->isWeeklyOff($day) => self::WEEKLY_OFF,
                ($leave = $leaves->first(fn ($l) => $day->betweenIncluded(Carbon::parse($l->start_date)->startOfDay(), Carbon::parse($l->end_date)->startOfDay()))) !== null => self::APPROVED_LEAVE,
                default => self::WORKING_DAY,
            };

            $days[$key] = [
                'state' => $state,
                'leave_days' => $state === self::APPROVED_LEAVE ? ($leave?->is_half_day ? 0.5 : 1.0) : 0.0,
            ];
        }

        return $days;
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
        return ! $this->isWeeklyOff($date) && ! $this->isMandatoryDay($date);
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
