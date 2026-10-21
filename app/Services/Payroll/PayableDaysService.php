<?php

namespace App\Services\Payroll;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\Attendance\AttendanceWindowService;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveYearResolver;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE payable-days answer for one employee and pay period (Phase 4 policy,
 * approved 16 Oct 2026). The payroll engine, the payslip snapshot, the
 * Payroll Attendance report and the attendance register all read it, so one
 * employee-period has one set of eligible / paid / LOP days.
 *
 * Basis: the actual eligible working days of the period — the employee's
 * scheduled days (weekly offs, their holidays and MDL shutdown days are not
 * working days and never LOP). Days are classified by AttendanceWindowService
 * / WorkingDayResolver, the rules every attendance screen uses.
 *
 *  - Not employed (before joining / after the last working day): unpaid,
 *    prorated on working days.
 *  - Approved paid leave: paid — except the part a leave balance could not
 *    cover. Only that excess is LOP, taken from the latest requests of the
 *    leave year (1 July → 30 June) that overdrew it.
 *  - Approved unpaid leave: LOP (sandwiched weekly offs too, when the request
 *    counted them).
 *  - A working day with no attendance and no leave: LOP once it is final —
 *    on or before the cutoff, with no pending leave / regularisation that
 *    could still explain it, and attendance is being recorded at all. Until
 *    then it is reported as pending and not deducted; a later correction
 *    reaches payroll as an adjustment in the next open run.
 *
 * The same classification gives THE attendance percentage (Phase 5 policy,
 * QA-F-079): attended units ÷ eligible units. Eligible = attended + approved
 * unpaid leave on a working day + finalised unexplained absence. Approved paid
 * leave, weekly offs, holidays, days outside employment, future days, today
 * before it is attended and absences that are still explainable (pending)
 * are never in the denominator. A half day counts ½. Read it through
 * AttendanceRateService.
 */
class PayableDaysService
{
    public const CODE_PRESENT = 'P';

    public const CODE_LATE = 'L';

    public const CODE_HALF_DAY = 'HD';

    public const CODE_PAID_LEAVE = 'LV';

    public const CODE_UNPAID_LEAVE = 'UL';

    public const CODE_ABSENT = 'A';

    public const CODE_PENDING = 'PD';

    public const CODE_WEEKLY_OFF = 'WO';

    public const CODE_HOLIDAY = 'H';

    public const CODE_NOT_EMPLOYED = 'NE';

    public const CODE_FUTURE = '-';

    /** @var array<string, string> */
    public const CODE_LABELS = [
        self::CODE_PRESENT => 'Present', self::CODE_LATE => 'Late', self::CODE_HALF_DAY => 'Half Day',
        self::CODE_PAID_LEAVE => 'Paid Leave', self::CODE_UNPAID_LEAVE => 'Unpaid Leave (LOP)', self::CODE_ABSENT => 'Absent (LOP)',
        self::CODE_PENDING => 'Pending — not deducted', self::CODE_WEEKLY_OFF => 'Weekly Off', self::CODE_HOLIDAY => 'Holiday',
        self::CODE_NOT_EMPLOYED => 'Not employed', self::CODE_FUTURE => 'Upcoming',
    ];

    /** Leave statuses that may still explain an absence. */
    private const PENDING_LEAVE = ['pending', 'pending_hr', 'more_info_requested'];

    public function __construct(
        private readonly AttendanceWindowService $window,
        private readonly WorkingDayResolver $workingDays,
        private readonly LeaveYearResolver $leaveYears,
        private readonly LeaveBalanceCalculator $balances,
    ) {}

    /**
     * @param  CarbonInterface|null  $lopFrom  first day absences / unpaid leave count (a salary-cycle move); defaults to $from
     * @param  CarbonInterface|null  $cutoff  last day an absence can be final; defaults to the period end, never after yesterday
     * @return array{from: string, to: string, cutoff: string, period_days: int, eligible_working_days: int, not_employed_working_days: int, working_days: int, present_days: float, paid_leave_days: float, unpaid_leave_days: float, absent_days: float, lop_days: float, paid_days: float, pending_days: float, weekly_off_days: int, holiday_days: int, attendance_tracked: bool, attended_units: float, attendance_eligible_units: float, days: array<string, array{code: string, lop: float}>}
     */
    public function forPeriod(Employee $employee, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $lopFrom = null, ?CarbonInterface $cutoff = null): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $lopStart = $lopFrom ? Carbon::parse($lopFrom)->startOfDay() : $start;
        $lastFinal = Carbon::parse($cutoff ?? $end)->startOfDay()->min(Carbon::yesterday());

        // Window: the earlier of the period and the LOP start (a cycle move
        // can reach back into the previous cycle's unpaid tail).
        $windowStart = $lopStart->lt($start) ? $lopStart : $start;
        $days = $this->window->days($employee, $windowStart, $end);

        // Scheduled days ignoring employment dates: the proration base.
        $unbounded = clone $employee;
        $unbounded->joining_date = null;
        $unbounded->setRelation('exitRecord', null);
        $schedule = $this->workingDays->classifyRange($unbounded, $start, $end);

        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$windowStart->toDateString(), $end->toDateString()])
            ->get(['date', 'check_in', 'status'])
            ->keyBy(fn (Attendance $a) => $a->date->toDateString());
        $tracked = $rows->contains(fn (Attendance $a) => $a->check_in !== null)
            || AttendancePunch::where('employee_id', $employee->id)->whereBetween('punch_date', [$windowStart->toDateString(), $end->toDateString()])->exists();

        $pending = $this->pendingDates($employee, $windowStart, $end);
        $unpaidByDate = $this->unpaidUnits($employee, $windowStart, $end);

        $result = [
            'eligible' => 0, 'not_employed' => 0, 'present' => 0.0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0,
            'absent' => 0.0, 'pending' => 0.0, 'weekly_off' => 0, 'holiday' => 0,
            'attended' => 0.0, 'attendance_eligible' => 0.0,
        ];
        $perDay = [];

        foreach ($days as $key => $day) {
            $date = Carbon::parse($key);
            $inPeriod = $date->gte($start);
            $countsLop = $date->gte($lopStart);
            $scheduledAnyway = in_array($schedule[$key]['state'] ?? null, [WorkingDayResolver::WORKING_DAY, WorkingDayResolver::APPROVED_LEAVE], true);
            if ($inPeriod && $scheduledAnyway) {
                $result['eligible']++;
            }

            $unpaid = $countsLop ? ($unpaidByDate[$key] ?? 0.0) : 0.0;
            $lop = 0.0;
            $code = match ($day['state']) {
                WorkingDayResolver::EMPLOYMENT_NOT_STARTED, WorkingDayResolver::EMPLOYMENT_ENDED => self::CODE_NOT_EMPLOYED,
                WorkingDayResolver::PUBLIC_HOLIDAY, WorkingDayResolver::MDL_SHUTDOWN => self::CODE_HOLIDAY,
                WorkingDayResolver::WEEKLY_OFF => self::CODE_WEEKLY_OFF,
                default => null,
            };

            if ($code === self::CODE_NOT_EMPLOYED) {
                if ($inPeriod && $scheduledAnyway) {
                    $result['not_employed']++;
                }
            } elseif ($code !== null) {
                // Weekly off / holiday: never LOP — unless an unpaid request
                // counted it as a leave day (the sandwich rule).
                $result[$code === self::CODE_HOLIDAY ? 'holiday' : 'weekly_off'] += $inPeriod ? 1 : 0;
                if ($unpaid > 0) {
                    $lop += $unpaid;
                    $result['unpaid_leave'] += $unpaid;
                    $code = self::CODE_UNPAID_LEAVE;
                }
            } else {
                $leaveUnits = (float) $day['leave_days'];
                $row = $rows->get($key);
                $final = $date->lte($lastFinal);
                $explainable = isset($pending[$key]);

                if ($day['state'] === WorkingDayResolver::APPROVED_LEAVE) {
                    $paidUnits = max(0.0, $leaveUnits - $unpaid);
                    $result['paid_leave'] += $paidUnits;
                    $result['unpaid_leave'] += $unpaid;
                    $lop += $unpaid;
                    $code = $unpaid > 0 ? self::CODE_UNPAID_LEAVE : self::CODE_PAID_LEAVE;
                    // Attendance %: unpaid leave stays in the denominator; paid leave never does.
                    $result['attendance_eligible'] += $inPeriod ? $unpaid : 0.0;
                }

                // The part of the day not on leave must be worked.
                $toWork = max(0.0, 1.0 - $leaveUnits);
                if ($toWork > 0) {
                    if ($day['attended']) {
                        $halfDay = $row?->status === 'half_day' ? 0.5 : 0.0;
                        $result['present'] += $toWork - min($halfDay, $toWork);
                        $missing = min($halfDay, $toWork);
                        if ($inPeriod) {
                            $result['attended'] += $toWork - $missing;
                            $result['attendance_eligible'] += $toWork - $missing;
                        }
                        $code ??= $halfDay > 0 ? self::CODE_HALF_DAY : ($day['status'] === 'late' ? self::CODE_LATE : self::CODE_PRESENT);
                    } else {
                        $missing = $toWork;
                        $code ??= $final ? self::CODE_ABSENT : self::CODE_FUTURE;
                    }

                    // Attendance %: only a finalised, unexplained absence lowers it.
                    if ($missing > 0 && $inPeriod && $final && $tracked && ! $explainable) {
                        $result['attendance_eligible'] += $missing;
                    }

                    if ($missing > 0 && $countsLop) {
                        if ($final && $tracked && ! $explainable) {
                            $result['absent'] += $missing;
                            $lop += $missing;
                        } elseif ($final) {
                            $result['pending'] += $missing;
                            $code = $code === self::CODE_ABSENT ? self::CODE_PENDING : $code;
                        }
                    }
                }
            }

            $perDay[$key] = ['code' => $code, 'lop' => round($lop, 2)];
        }

        $working = $result['eligible'] - $result['not_employed'];
        $lopDays = round($result['unpaid_leave'] + $result['absent'], 2);

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'cutoff' => $lastFinal->toDateString(),
            'period_days' => (int) $start->diffInDays($end) + 1,
            'eligible_working_days' => $result['eligible'],
            'not_employed_working_days' => $result['not_employed'],
            'working_days' => $working,
            'present_days' => round($result['present'], 2),
            'paid_leave_days' => round($result['paid_leave'], 2),
            'unpaid_leave_days' => round($result['unpaid_leave'], 2),
            'absent_days' => round($result['absent'], 2),
            'lop_days' => $lopDays,
            'paid_days' => round(max(0.0, $working - $lopDays), 2),
            'pending_days' => round($result['pending'], 2),
            'weekly_off_days' => $result['weekly_off'],
            'holiday_days' => $result['holiday'],
            'attendance_tracked' => $tracked,
            'attended_units' => round($result['attended'], 2),
            'attendance_eligible_units' => round($result['attendance_eligible'], 2),
            'days' => array_filter($perDay, fn ($d, $key) => Carbon::parse($key)->gte($start), ARRAY_FILTER_USE_BOTH),
        ];
    }

    /**
     * Dates a pending leave request or regularisation may still explain.
     *
     * @return array<string, true>
     */
    private function pendingDates(Employee $employee, Carbon $start, Carbon $end): array
    {
        $dates = [];
        LeaveRequest::where('employee_id', $employee->id)->whereIn('status', self::PENDING_LEAVE)
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())
            ->get(['start_date', 'end_date'])
            ->each(function (LeaveRequest $r) use (&$dates) {
                foreach (CarbonPeriod::create($r->start_date, $r->end_date) as $d) {
                    $dates[$d->toDateString()] = true;
                }
            });
        AttendanceRegularisation::where('employee_id', $employee->id)->where('status', 'pending')
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('work_date')
            ->each(function ($d) use (&$dates) {
                $dates[Carbon::parse($d)->toDateString()] = true;
            });

        return $dates;
    }

    /**
     * Unpaid leave units per date: every day of an unpaid request, and for a
     * paid request only the tail its balance could not cover.
     *
     * @return array<string, float>
     */
    private function unpaidUnits(Employee $employee, Carbon $start, Carbon $end): array
    {
        $requests = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())
            ->get();

        $unpaidByDate = [];
        foreach ($requests as $request) {
            // A V2 policy may convert part of a paid request to LWP; that part
            // is unpaid from the start, on top of any overdrawn tail.
            $unpaid = $request->effectiveLeaveStatus() === 'unpaid'
                ? (float) $request->days
                : round((float) ($request->lwp_days ?? 0) + $this->uncoveredDays($request), 2);
            if ($unpaid <= 0) {
                continue;
            }

            // The unpaid units sit on the request's last countable days.
            $remaining = $unpaid;
            foreach (array_reverse($this->countableDates($employee, $request)) as $date) {
                if ($remaining <= 0) {
                    break;
                }
                $units = min($request->is_half_day ? 0.5 : 1.0, $remaining);
                $remaining -= $units;
                if ($date >= $start->toDateString() && $date <= $end->toDateString()) {
                    $unpaidByDate[$date] = ($unpaidByDate[$date] ?? 0.0) + $units;
                }
            }
        }

        return $unpaidByDate;
    }

    /**
     * Dates a request counted as leave days: every calendar day when it
     * counted the whole span (sandwich), else its leave days only.
     *
     * @return array<int, string> ascending
     */
    private function countableDates(Employee $employee, LeaveRequest $request): array
    {
        $from = Carbon::parse($request->start_date)->startOfDay();
        $to = Carbon::parse($request->end_date)->startOfDay();
        $span = (int) $from->diffInDays($to) + 1;
        $wholeSpan = ! $request->is_half_day && (float) $request->days >= $span;

        $states = $this->workingDays->classifyRange($employee, $from, $to);

        return array_keys(array_filter($states, fn ($d) => $wholeSpan
            ? ! in_array($d['state'], [WorkingDayResolver::EMPLOYMENT_NOT_STARTED, WorkingDayResolver::EMPLOYMENT_ENDED], true)
            : $d['state'] === WorkingDayResolver::APPROVED_LEAVE));
    }

    /**
     * How much of a paid request its balance could not cover: the leave
     * year's overdraw (−approved available), allocated to the latest approved
     * paid requests charging that balance first — they are the ones that
     * overdrew it. A V2 Earned Leave reason charges the shared balance.
     */
    private function uncoveredDays(LeaveRequest $request): float
    {
        $year = $this->leaveYears->forDate(Carbon::parse($request->start_date));
        $balance = LeaveBalance::where('employee_id', $request->employee_id)
            ->where('leave_type_id', $request->balanceTypeId())
            ->where('year', $year->legacyYear())
            ->first();
        if ($balance === null) {
            return 0.0;
        }

        $overdraw = max(0.0, -(float) $this->balances->summary($balance)['approved_available']);
        if ($overdraw <= 0) {
            return 0.0;
        }

        /** @var Collection<int, LeaveRequest> $sameYear */
        $sameYear = LeaveRequest::with('leaveType')
            ->where('employee_id', $request->employee_id)->chargedTo($request->balanceTypeId())->where('status', 'approved')
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->orderByDesc('start_date')->orderByDesc('id')
            ->get()
            ->filter(fn (LeaveRequest $r) => $r->effectiveLeaveStatus() !== 'unpaid');

        foreach ($sameYear as $candidate) {
            $share = min($candidate->paidDays(), $overdraw);
            if ($candidate->id === $request->id) {
                return round($share, 2);
            }
            $overdraw -= $share;
            if ($overdraw <= 0) {
                return 0.0;
            }
        }

        return 0.0;
    }
}
