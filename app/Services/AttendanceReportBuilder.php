<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OvertimeRecord;
use App\Models\PublicHoliday;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds every enterprise attendance report from a single filter set, so the
 * live Livewire preview and the CSV / PDF exports share one source of truth.
 *
 * A report is returned as a normalised array:
 *   ['title', 'subtitle', 'columns', 'rows', 'summary', 'trend'].
 * Rows are arrays of scalars aligned to columns — safe to render or stream.
 */
class AttendanceReportBuilder
{
    /** @var array<string, string> Report keys → human labels. */
    public const TYPES = [
        'daily' => 'Daily Report',
        'monthly' => 'Monthly Report',
        'register' => 'Monthly Attendance Register',
        'muster' => 'Muster Roll',
        'late' => 'Late Report',
        'absent' => 'Absent Report',
        'holiday' => 'Holiday Report',
        'leave' => 'Leave Report',
        'leave_summary' => 'Leave Summary',
        'comp_off' => 'Comp-Off Summary',
        'overtime' => 'Overtime Report',
        'payroll_attendance' => 'Payroll Attendance Report',
        'working_hours' => 'Working Hours Report',
        'department' => 'Department Report',
        'employee' => 'Employee Report',
        'biometric' => 'Biometric Report',
        'regularization' => 'Regularization Report',
    ];

    /**
     * Day codes used by the register and payroll reports, matching the
     * shorthand HR already uses on their own attendance sheet.
     */
    private const CODE_PRESENT = 'P';

    private const CODE_ABSENT = 'A';

    private const CODE_LATE = 'L';

    private const CODE_HALF_DAY = 'HD';

    private const CODE_LEAVE = 'LV';

    private const CODE_WEEKLY_OFF = 'WO';

    private const CODE_HOLIDAY = 'H';

    private const CODE_FUTURE = '-';

    /**
     * @param  array{from?:string,to?:string,department_id?:int|string|null,office_id?:int|string|null,shift_id?:int|string|null,employee_id?:int|string|null,mode?:string|null}  $filters
     * @return array{title:string,subtitle:string,columns:array<int,string>,rows:array<int,array<int,mixed>>,summary:array<int,array{label:string,value:mixed}>,trend:array{labels:array<int,string>,data:array<int,int|float>}|null}
     */
    public function build(string $type, array $filters): array
    {
        [$from, $to] = $this->range($filters);
        $subtitle = $from->isSameDay($to)
            ? $from->format('d M Y')
            : $from->format('d M Y').' – '.$to->format('d M Y');

        $report = match ($type) {
            'monthly' => $this->monthly($from, $to, $filters),
            'register' => $this->register($from, $to, $filters),
            'muster' => $this->muster($from, $to, $filters),
            'late' => $this->late($from, $to, $filters),
            'absent' => $this->absent($from, $to, $filters),
            'holiday' => $this->holiday($from, $to),
            'leave' => $this->leave($from, $to, $filters),
            'leave_summary' => $this->leaveSummary($from, $to, $filters),
            'comp_off' => $this->compOff($from, $to, $filters),
            'payroll_attendance' => $this->payrollAttendance($from, $to, $filters),
            'overtime' => $this->overtime($from, $to, $filters),
            'working_hours' => $this->workingHours($from, $to, $filters),
            'department' => $this->department($from, $to, $filters),
            'employee' => $this->employee($from, $to, $filters),
            'biometric' => $this->biometric($from, $to, $filters),
            'regularization' => $this->regularization($from, $to, $filters),
            default => $this->daily($from, $to, $filters),
        };

        return array_merge([
            'title' => self::TYPES[$type] ?? self::TYPES['daily'],
            'subtitle' => $subtitle,
            'summary' => [],
            'trend' => null,
        ], $report);
    }

    /** @return array{0:Carbon,1:Carbon} */
    protected function range(array $filters): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : Carbon::now()->startOfMonth();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : Carbon::now()->endOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /**
     * Apply the shared employee/mode filters to an Attendance-like query whose
     * employee relation is named `employee` and that has a `work_mode` column.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    protected function applyFilters(Builder $query, array $filters, bool $hasMode = true): void
    {
        $query->whereHas('employee', function (Builder $e) use ($filters) {
            $e->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
                ->when($filters['office_id'] ?? null, fn ($q, $v) => $q->where('office_id', $v))
                ->when($filters['shift_id'] ?? null, fn ($q, $v) => $q->where('shift_id', $v))
                ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('id', $v));
        });

        if ($hasMode && ! empty($filters['mode'])) {
            $query->where('work_mode', $filters['mode']);
        }
    }

    /** Employee query narrowed by the placement filters (no attendance join). */
    protected function employeeQuery(array $filters): Builder
    {
        return Employee::query()->whereHas('user')
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['office_id'] ?? null, fn ($q, $v) => $q->where('office_id', $v))
            ->when($filters['shift_id'] ?? null, fn ($q, $v) => $q->where('shift_id', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->where('status', 'active');
    }

    protected function attendanceQuery(Carbon $from, Carbon $to, array $filters): Builder
    {
        $q = Attendance::with('employee.user', 'employee.department', 'employee.office')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $this->applyFilters($q, $filters);

        return $q;
    }

    protected function hm(?int $minutes): string
    {
        $minutes = max(0, (int) $minutes);

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    // ── Reports ──────────────────────────────────────────────────────────────

    protected function daily(Carbon $from, Carbon $to, array $filters): array
    {
        $rows = $this->attendanceQuery($from, $to, $filters)
            ->orderBy('date')->orderBy('employee_id')->get();

        $data = $rows->map(fn ($a) => [
            $a->employee?->employee_id ?? '—',
            $a->employee?->user?->name ?? '—',
            $a->employee?->department?->name ?? '—',
            $a->date->format('d M Y'),
            $a->check_in?->format('h:i A') ?? '—',
            $a->check_out?->format('h:i A') ?? '—',
            $a->total_hours ? number_format((float) $a->total_hours, 1).'h' : '—',
            $a->is_late ? 'Late '.(int) ($a->late_minutes ?? 0).'m' : ucfirst($a->status ?? '—'),
            ucfirst($a->work_mode ?? '—'),
        ])->all();

        $present = $rows->whereNotNull('check_in')->count();
        $late = $rows->where('is_late', true)->count();

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Date', 'Check In', 'Check Out', 'Hours', 'Status', 'Mode'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Records', 'value' => $rows->count()],
                ['label' => 'Present', 'value' => $present],
                ['label' => 'Late', 'value' => $late],
                ['label' => 'On Leave/Remote', 'value' => $rows->where('status', 'remote')->count()],
            ],
            'trend' => $this->dailyTrend($from, $to, $filters),
        ];
    }

    protected function monthly(Carbon $from, Carbon $to, array $filters): array
    {
        $rows = $this->attendanceQuery($from, $to, $filters)->get()->groupBy('employee_id');
        $workDays = max(1, AttendanceSetting::workingDaysBetween($from, $to));

        $data = [];
        $totPresent = 0;
        foreach ($rows as $group) {
            $emp = $group->first()->employee;
            $present = $group->whereNotNull('check_in')->count();
            $late = $group->where('is_late', true)->count();
            $hours = $group->sum(fn ($a) => (float) $a->total_hours);
            $totPresent += $present;
            $data[] = [
                $emp?->employee_id ?? '—',
                $emp?->user?->name ?? '—',
                $emp?->department?->name ?? '—',
                $present,
                max(0, $workDays - $present),
                $late,
                number_format($hours, 1).'h',
                min(100, round($present / $workDays * 100)).'%',
            ];
        }

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Present', 'Absent', 'Late', 'Total Hours', 'Attendance %'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Employees', 'value' => count($data)],
                ['label' => 'Working Days', 'value' => $workDays],
                ['label' => 'Avg Present', 'value' => count($data) ? round($totPresent / count($data), 1) : 0],
            ],
            'trend' => $this->dailyTrend($from, $to, $filters),
        ];
    }

    protected function muster(Carbon $from, Carbon $to, array $filters): array
    {
        $days = collect(CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()))->take(31);
        $employees = $this->employeeQuery($filters)->with('user')->orderBy('id')->limit(60)->get();
        $empIds = $employees->pluck('id');

        $att = Attendance::whereIn('employee_id', $empIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('employee_id');
        $holidays = PublicHoliday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()->keyBy(fn ($h) => Carbon::parse($h->date)->toDateString());

        $columns = array_merge(['Employee'], $days->map(fn ($d) => $d->format('d'))->all());
        $rows = [];
        foreach ($employees as $emp) {
            $byDate = ($att->get($emp->id) ?? collect())->keyBy(fn ($a) => $a->date->toDateString());
            $cells = [$emp->user?->name ?? '—'];
            foreach ($days as $day) {
                $key = $day->toDateString();
                $cells[] = match (true) {
                    isset($holidays[$key]) => 'H',
                    $this->isWeeklyOff($day) => 'W',
                    isset($byDate[$key]) && $byDate[$key]->is_late => 'L',
                    isset($byDate[$key]) && $byDate[$key]->check_in => 'P',
                    $day->gt(now()) => '·',
                    default => 'A',
                };
            }
            $rows[] = $cells;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'summary' => [
                ['label' => 'Employees', 'value' => $employees->count()],
                ['label' => 'Days', 'value' => $days->count()],
                ['label' => 'Legend', 'value' => 'P Present · L Late · A Absent · W Weekend · H Holiday'],
            ],
        ];
    }

    /**
     * Build the per-employee, per-day code grid the register and payroll
     * reports both need. Unlike muster() this is uncapped and returns the
     * codes HR uses on their own sheet, so the two can be compared directly.
     *
     * @return array{days: Collection, employees: Collection, grid: array<int, array<string, string>>}
     */
    protected function dayCodeGrid(Carbon $from, Carbon $to, array $filters): array
    {
        $days = collect(CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()));
        $employees = $this->employeeQuery($filters)
            ->with('user', 'department')
            ->get()
            ->sortBy(fn (Employee $e) => $e->user?->name ?? '')
            ->values();

        $empIds = $employees->pluck('id');

        $attendance = Attendance::whereIn('employee_id', $empIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('employee_id');

        $holidays = PublicHoliday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')
            ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true]);

        // Approved leave, expanded per covered day, so a leave day reads LV
        // rather than being miscounted as an absence.
        $leaveDays = [];
        $leaveRequests = LeaveRequest::whereIn('employee_id', $empIds)
            ->where('status', 'approved')
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get();
        foreach ($leaveRequests as $leave) {
            foreach (CarbonPeriod::create(Carbon::parse($leave->start_date), Carbon::parse($leave->end_date)) as $day) {
                $leaveDays[$leave->employee_id][$day->toDateString()] = true;
            }
        }

        $grid = [];
        foreach ($employees as $employee) {
            $byDate = ($attendance->get($employee->id) ?? collect())->keyBy(fn ($a) => $a->date->toDateString());
            $cells = [];

            foreach ($days as $day) {
                $key = $day->toDateString();
                $record = $byDate[$key] ?? null;

                $cells[$key] = match (true) {
                    // A worked day wins over any calendar classification.
                    $record && $record->status === 'half_day' => self::CODE_HALF_DAY,
                    $record && $record->is_late => self::CODE_LATE,
                    $record && $record->check_in !== null => self::CODE_PRESENT,
                    isset($holidays[$key]) => self::CODE_HOLIDAY,
                    isset($leaveDays[$employee->id][$key]) => self::CODE_LEAVE,
                    $this->isWeeklyOff($day) => self::CODE_WEEKLY_OFF,
                    $day->gt(now()) => self::CODE_FUTURE,
                    default => self::CODE_ABSENT,
                };
            }

            $grid[$employee->id] = $cells;
        }

        return ['days' => $days, 'employees' => $employees, 'grid' => $grid];
    }

    /** Whether a date is a non-working day, per the configured working week. */
    protected function isWeeklyOff(Carbon $day): bool
    {
        return AttendanceSetting::isWeeklyOff($day);
    }

    /**
     * Monthly Attendance Register — the day grid plus trailing totals, in the
     * shape HR's own sheet uses so the two can be diffed column for column.
     */
    protected function register(Carbon $from, Carbon $to, array $filters): array
    {
        ['days' => $days, 'employees' => $employees, 'grid' => $grid] = $this->dayCodeGrid($from, $to, $filters);

        $columns = array_merge(
            ['Emp ID', 'Employee', 'Department'],
            $days->map(fn ($d) => $d->format('d'))->all(),
            ['P', 'A', 'L', 'HD', 'LV', 'WO', 'H', 'Payable Days'],
        );

        $rows = [];
        foreach ($employees as $employee) {
            $cells = $grid[$employee->id] ?? [];
            $tally = array_count_values($cells);

            $present = $tally[self::CODE_PRESENT] ?? 0;
            $late = $tally[self::CODE_LATE] ?? 0;
            $halfDay = $tally[self::CODE_HALF_DAY] ?? 0;
            $leave = $tally[self::CODE_LEAVE] ?? 0;
            $weeklyOff = $tally[self::CODE_WEEKLY_OFF] ?? 0;
            $holiday = $tally[self::CODE_HOLIDAY] ?? 0;

            $rows[] = array_merge(
                [
                    $employee->employee_id ?? '—',
                    $employee->user?->name ?? '—',
                    $employee->department?->name ?? '—',
                ],
                array_values($cells),
                [
                    // Late still counts as a present day.
                    $present + $late,
                    $tally[self::CODE_ABSENT] ?? 0,
                    $late,
                    $halfDay,
                    $leave,
                    $weeklyOff,
                    $holiday,
                    $this->payableDays($present, $late, $halfDay, $leave, $weeklyOff, $holiday),
                ],
            );
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'summary' => [
                ['label' => 'Employees', 'value' => $employees->count()],
                ['label' => 'Days', 'value' => $days->count()],
                ['label' => 'Legend', 'value' => 'P Present · L Late · HD Half Day · LV Leave · A Absent · WO Weekly Off · H Holiday'],
            ],
        ];
    }

    /** Paid days: worked + paid leave + weekly offs + holidays, with half days at 0.5. */
    protected function payableDays(int $present, int $late, int $halfDay, int $leave, int $weeklyOff, int $holiday): float
    {
        return round($present + $late + ($halfDay * 0.5) + $leave + $weeklyOff + $holiday, 1);
    }

    /**
     * Payroll Attendance Report — the bridge between attendance and payroll:
     * how many days each employee is actually paid for, and how many are lost.
     */
    protected function payrollAttendance(Carbon $from, Carbon $to, array $filters): array
    {
        ['days' => $days, 'employees' => $employees, 'grid' => $grid] = $this->dayCodeGrid($from, $to, $filters);

        $rows = [];
        $totalPayable = 0.0;
        $totalLop = 0;

        foreach ($employees as $employee) {
            $tally = array_count_values($grid[$employee->id] ?? []);

            $present = $tally[self::CODE_PRESENT] ?? 0;
            $late = $tally[self::CODE_LATE] ?? 0;
            $halfDay = $tally[self::CODE_HALF_DAY] ?? 0;
            $leave = $tally[self::CODE_LEAVE] ?? 0;
            $weeklyOff = $tally[self::CODE_WEEKLY_OFF] ?? 0;
            $holiday = $tally[self::CODE_HOLIDAY] ?? 0;
            $absent = $tally[self::CODE_ABSENT] ?? 0;

            $payable = $this->payableDays($present, $late, $halfDay, $leave, $weeklyOff, $holiday);
            // Unapproved absence is loss of pay; a half day loses half.
            $lop = round($absent + ($halfDay * 0.5), 1);

            $totalPayable += $payable;
            $totalLop += $lop;

            $rows[] = [
                $employee->employee_id ?? '—',
                $employee->user?->name ?? '—',
                $employee->department?->name ?? '—',
                $days->count(),
                $present + $late,
                $halfDay,
                $leave,
                $weeklyOff,
                $holiday,
                $absent,
                $lop,
                $payable,
            ];
        }

        return [
            'columns' => [
                'Emp ID', 'Employee', 'Department', 'Calendar Days', 'Present', 'Half Days',
                'Paid Leave', 'Weekly Offs', 'Holidays', 'Absent', 'LOP Days', 'Payable Days',
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Employees', 'value' => $employees->count()],
                ['label' => 'Total Payable Days', 'value' => round($totalPayable, 1)],
                ['label' => 'Total LOP Days', 'value' => round($totalLop, 1)],
            ],
        ];
    }

    /**
     * Leave Summary — the per-employee, per-type balance rollup. Distinct from
     * the `leave` report, which lists individual requests.
     */
    protected function leaveSummary(Carbon $from, Carbon $to, array $filters): array
    {
        $year = (int) $to->year;
        $employeeIds = $this->employeeQuery($filters)->pluck('id');

        $balances = LeaveBalance::with(['employee.user', 'employee.department', 'leaveType'])
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->get()
            ->sortBy(fn (LeaveBalance $b) => [$b->employee?->user?->name ?? '', $b->leaveType?->name ?? ''])
            ->values();

        $rows = $balances->map(function (LeaveBalance $balance) {
            $opening = (float) $balance->allocated_days + (float) $balance->carried_forward_days;

            return [
                $balance->employee?->employee_id ?? '—',
                $balance->employee?->user?->name ?? '—',
                $balance->employee?->department?->name ?? '—',
                $balance->leaveType?->name ?? '—',
                number_format($opening, 1),
                number_format((float) $balance->carried_forward_days, 1),
                number_format((float) $balance->used_days, 1),
                number_format($balance->pendingDays(), 1),
                number_format((float) ($balance->encashed_days ?? 0), 1),
                number_format($balance->available(), 1),
            ];
        })->all();

        return [
            'columns' => [
                'Emp ID', 'Employee', 'Department', 'Leave Type',
                'Opening', 'Carried Forward', 'Availed', 'Pending', 'Encashed', 'Closing Balance',
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Year', 'value' => $year],
                ['label' => 'Employees', 'value' => $balances->pluck('employee_id')->unique()->count()],
                ['label' => 'Total Availed', 'value' => number_format((float) $balances->sum(fn ($b) => (float) $b->used_days), 1)],
            ],
        ];
    }

    /** Comp-Off Summary — credits earned against comp-off leave taken. */
    protected function compOff(Carbon $from, Carbon $to, array $filters): array
    {
        $year = (int) $to->year;
        $employeeIds = $this->employeeQuery($filters)->pluck('id');

        $balances = LeaveBalance::with(['employee.user', 'employee.department', 'leaveType'])
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->whereHas('leaveType', fn ($q) => $q->where('category', 'comp_off'))
            ->get()
            ->sortBy(fn (LeaveBalance $b) => $b->employee?->user?->name ?? '')
            ->values();

        $rows = $balances->map(fn (LeaveBalance $balance) => [
            $balance->employee?->employee_id ?? '—',
            $balance->employee?->user?->name ?? '—',
            $balance->employee?->department?->name ?? '—',
            number_format((float) ($balance->comp_off_credits ?? 0), 1),
            number_format((float) $balance->used_days, 1),
            number_format($balance->pendingDays(), 1),
            number_format($balance->available(), 1),
        ])->all();

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Earned', 'Availed', 'Pending', 'Balance'],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Year', 'value' => $year],
                ['label' => 'Employees', 'value' => $balances->count()],
                ['label' => 'Total Earned', 'value' => number_format((float) $balances->sum(fn ($b) => (float) ($b->comp_off_credits ?? 0)), 1)],
                ['label' => 'Total Availed', 'value' => number_format((float) $balances->sum(fn ($b) => (float) $b->used_days), 1)],
            ],
        ];
    }

    protected function late(Carbon $from, Carbon $to, array $filters): array
    {
        $rows = $this->attendanceQuery($from, $to, $filters)
            ->where('is_late', true)->orderBy('date')->get();

        $data = $rows->map(fn ($a) => [
            $a->employee?->employee_id ?? '—',
            $a->employee?->user?->name ?? '—',
            $a->employee?->department?->name ?? '—',
            $a->date->format('d M Y'),
            $a->check_in?->format('h:i A') ?? '—',
            (int) ($a->late_minutes ?? 0).'m',
        ])->all();

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Date', 'Check In', 'Late By'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Late Instances', 'value' => $rows->count()],
                ['label' => 'Total Late', 'value' => $this->hm((int) $rows->sum('late_minutes'))],
                ['label' => 'Avg Late', 'value' => $rows->count() ? round($rows->avg('late_minutes')).'m' : '0m'],
            ],
            'trend' => $this->dailyTrend($from, $to, $filters, fn ($g) => $g->where('is_late', true)->count()),
        ];
    }

    protected function absent(Carbon $from, Carbon $to, array $filters): array
    {
        $employees = $this->employeeQuery($filters)->with('user', 'department')->get();
        $att = Attendance::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('check_in')->get()
            ->groupBy('employee_id')
            ->map(fn ($g) => $g->keyBy(fn ($a) => $a->date->toDateString()));
        $holidays = PublicHoliday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();

        $data = [];
        $cutoff = min($to, Carbon::yesterday()->endOfDay());
        foreach ($employees as $emp) {
            $present = $att->get($emp->id) ?? collect();
            foreach (CarbonPeriod::create($from->copy()->startOfDay(), $cutoff) as $day) {
                $key = $day->toDateString();
                if ($this->isWeeklyOff($day) || isset($holidays[$key]) || isset($present[$key])) {
                    continue;
                }
                $data[] = [
                    $emp->employee_id ?? '—',
                    $emp->user?->name ?? '—',
                    $emp->department?->name ?? '—',
                    $day->format('d M Y'),
                    $day->format('l'),
                ];
            }
        }

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Date', 'Day'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Absent Records', 'value' => count($data)],
                ['label' => 'Employees', 'value' => $employees->count()],
            ],
        ];
    }

    protected function holiday(Carbon $from, Carbon $to): array
    {
        $rows = PublicHoliday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->get();

        return [
            'columns' => ['Date', 'Day', 'Holiday', 'Country'],
            'rows' => $rows->map(fn ($h) => [
                Carbon::parse($h->date)->format('d M Y'),
                Carbon::parse($h->date)->format('l'),
                $h->name,
                $h->country ?? '—',
            ])->all(),
            'summary' => [['label' => 'Holidays', 'value' => $rows->count()]],
        ];
    }

    protected function leave(Carbon $from, Carbon $to, array $filters): array
    {
        $q = LeaveRequest::with('employee.user', 'employee.department', 'leaveType')
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString());
        $q->whereHas('employee', function (Builder $e) use ($filters) {
            $e->when($filters['department_id'] ?? null, fn ($x, $v) => $x->where('department_id', $v))
                ->when($filters['office_id'] ?? null, fn ($x, $v) => $x->where('office_id', $v))
                ->when($filters['employee_id'] ?? null, fn ($x, $v) => $x->where('id', $v));
        });
        $rows = $q->orderByDesc('start_date')->get();

        $data = $rows->map(fn ($l) => [
            $l->employee?->user?->name ?? '—',
            $l->employee?->department?->name ?? '—',
            $l->leaveType?->name ?? 'Leave',
            Carbon::parse($l->start_date)->format('d M').' – '.Carbon::parse($l->end_date)->format('d M Y'),
            $l->days,
            ucfirst($l->status),
        ])->all();

        return [
            'columns' => ['Employee', 'Department', 'Type', 'Dates', 'Days', 'Status'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Requests', 'value' => $rows->count()],
                ['label' => 'Approved', 'value' => $rows->where('status', 'approved')->count()],
                ['label' => 'Total Days', 'value' => number_format((float) $rows->where('status', 'approved')->sum('days'), 1)],
            ],
        ];
    }

    protected function overtime(Carbon $from, Carbon $to, array $filters): array
    {
        $q = OvertimeRecord::with('employee.user', 'employee.department')
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()]);
        $q->whereHas('employee', function (Builder $e) use ($filters) {
            $e->when($filters['department_id'] ?? null, fn ($x, $v) => $x->where('department_id', $v))
                ->when($filters['office_id'] ?? null, fn ($x, $v) => $x->where('office_id', $v))
                ->when($filters['employee_id'] ?? null, fn ($x, $v) => $x->where('id', $v));
        });
        $rows = $q->orderBy('work_date')->get();

        $data = $rows->map(fn ($o) => [
            $o->employee?->employee_id ?? '—',
            $o->employee?->user?->name ?? '—',
            $o->employee?->department?->name ?? '—',
            Carbon::parse($o->work_date)->format('d M Y'),
            number_format((float) $o->ot_hours, 1).'h',
            $o->ot_amount ? number_format((float) $o->ot_amount, 2) : '—',
            $o->is_paid ? 'Paid' : 'Pending',
        ])->all();

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Date', 'OT Hours', 'Amount', 'Paid'],
            'rows' => $data,
            'summary' => [
                ['label' => 'OT Records', 'value' => $rows->count()],
                ['label' => 'Total OT Hours', 'value' => number_format((float) $rows->sum('ot_hours'), 1).'h'],
                ['label' => 'Total Amount', 'value' => number_format((float) $rows->sum('ot_amount'), 2)],
            ],
        ];
    }

    protected function workingHours(Carbon $from, Carbon $to, array $filters): array
    {
        $rows = $this->attendanceQuery($from, $to, $filters)->get()->groupBy('employee_id');

        $data = [];
        foreach ($rows as $group) {
            $emp = $group->first()->employee;
            $hours = $group->sum(fn ($a) => (float) $a->total_hours);
            $days = $group->whereNotNull('check_out')->count();
            $breakMin = (int) $group->sum('break_minutes');
            $data[] = [
                $emp?->employee_id ?? '—',
                $emp?->user?->name ?? '—',
                $emp?->department?->name ?? '—',
                $days,
                number_format($hours, 1).'h',
                $days ? number_format($hours / $days, 1).'h' : '0h',
                $this->hm($breakMin),
            ];
        }

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Days Worked', 'Total Hours', 'Avg/Day', 'Total Break'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Employees', 'value' => count($data)],
                ['label' => 'Total Hours', 'value' => number_format($rows->flatten(1)->sum(fn ($a) => (float) $a->total_hours), 1).'h'],
            ],
        ];
    }

    protected function department(Carbon $from, Carbon $to, array $filters): array
    {
        $rows = $this->attendanceQuery($from, $to, $filters)->get()
            ->groupBy(fn ($a) => $a->employee?->department?->name ?? '—');

        $data = [];
        $trendLabels = [];
        $trendData = [];
        foreach ($rows as $dept => $group) {
            $present = $group->whereNotNull('check_in')->count();
            $late = $group->where('is_late', true)->count();
            $pct = $group->count() ? round($present / $group->count() * 100) : 0;
            $data[] = [
                $dept,
                $group->pluck('employee_id')->unique()->count(),
                $present,
                $late,
                number_format($group->sum(fn ($a) => (float) $a->total_hours), 1).'h',
                $pct.'%',
            ];
            $trendLabels[] = $dept;
            $trendData[] = $pct;
        }

        return [
            'columns' => ['Department', 'Employees', 'Present', 'Late', 'Total Hours', 'Attendance %'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Departments', 'value' => count($data)],
            ],
            'trend' => ['labels' => $trendLabels, 'data' => $trendData],
        ];
    }

    protected function employee(Carbon $from, Carbon $to, array $filters): array
    {
        $rows = $this->attendanceQuery($from, $to, $filters)->orderBy('date')->get();

        $data = $rows->map(fn ($a) => [
            $a->date->format('d M Y'),
            $a->date->format('D'),
            $a->check_in?->format('h:i A') ?? '—',
            $a->check_out?->format('h:i A') ?? '—',
            $a->total_hours ? number_format((float) $a->total_hours, 1).'h' : '—',
            (int) ($a->break_minutes ?? 0).'m',
            $a->is_late ? 'Late '.(int) ($a->late_minutes ?? 0).'m' : ucfirst($a->status ?? '—'),
            ucfirst($a->work_mode ?? '—'),
        ])->all();

        $name = $rows->first()?->employee?->user?->name
            ?? Employee::with('user')->find($filters['employee_id'] ?? 0)?->user?->name
            ?? 'Employee';

        return [
            'title' => 'Employee Report — '.$name,
            'columns' => ['Date', 'Day', 'Check In', 'Check Out', 'Hours', 'Break', 'Status', 'Mode'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Present Days', 'value' => $rows->whereNotNull('check_in')->count()],
                ['label' => 'Late Days', 'value' => $rows->where('is_late', true)->count()],
                ['label' => 'Total Hours', 'value' => number_format($rows->sum(fn ($a) => (float) $a->total_hours), 1).'h'],
            ],
            'trend' => $this->dailyTrend($from, $to, $filters),
        ];
    }

    protected function biometric(Carbon $from, Carbon $to, array $filters): array
    {
        $q = AttendanceDailySummary::with('employee.user')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', $filters['employee_id']);
        }
        $rows = $q->orderByDesc('date')->limit(500)->get();

        $data = $rows->map(fn ($s) => [
            $s->employee?->user?->name ?? ('PIN '.$s->employee_code),
            $s->date->format('d M Y'),
            $s->first_punch ? Carbon::parse($s->first_punch)->format('h:i A') : '—',
            $s->last_punch ? Carbon::parse($s->last_punch)->format('h:i A') : '—',
            (int) $s->raw_punch_count,
            $s->device_serial ?? '—',
            $s->synced_at ? Carbon::parse($s->synced_at)->format('d M h:i A') : '—',
        ])->all();

        return [
            'columns' => ['Employee', 'Date', 'First Punch', 'Last Punch', 'Punches', 'Device', 'Synced'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Days Synced', 'value' => $rows->count()],
                ['label' => 'Total Punches', 'value' => (int) $rows->sum('raw_punch_count')],
                ['label' => 'Devices', 'value' => $rows->pluck('device_serial')->filter()->unique()->count()],
            ],
        ];
    }

    protected function regularization(Carbon $from, Carbon $to, array $filters): array
    {
        $q = AttendanceRegularisation::with('employee.user', 'reviewer')
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()]);
        $q->whereHas('employee', function (Builder $e) use ($filters) {
            $e->when($filters['department_id'] ?? null, fn ($x, $v) => $x->where('department_id', $v))
                ->when($filters['employee_id'] ?? null, fn ($x, $v) => $x->where('id', $v));
        });
        $rows = $q->orderByDesc('work_date')->get();

        $data = $rows->map(fn ($r) => [
            $r->employee?->user?->name ?? '—',
            Carbon::parse($r->work_date)->format('d M Y'),
            Carbon::parse($r->requested_check_in)->format('h:i A').' → '.Carbon::parse($r->requested_check_out)->format('h:i A'),
            Str::limit($r->reason, 40),
            ucfirst($r->status),
            $r->reviewer?->name ?? '—',
        ])->all();

        return [
            'columns' => ['Employee', 'Work Date', 'Requested', 'Reason', 'Status', 'Reviewer'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Requests', 'value' => $rows->count()],
                ['label' => 'Approved', 'value' => $rows->where('status', 'approved')->count()],
                ['label' => 'Pending', 'value' => $rows->where('status', 'pending')->count()],
            ],
        ];
    }

    /**
     * Daily present-count trend across the range (capped at 62 points).
     *
     * @return array{labels:array<int,string>,data:array<int,int>}
     */
    protected function dailyTrend(Carbon $from, Carbon $to, array $filters, ?\Closure $reducer = null): array
    {
        if ($from->diffInDays($to) > 62) {
            return ['labels' => [], 'data' => []];
        }

        $byDay = $this->attendanceQuery($from, $to, $filters)->get()
            ->groupBy(fn ($a) => $a->date->toDateString());
        $reducer ??= fn ($g) => $g->whereNotNull('check_in')->count();

        $labels = [];
        $data = [];
        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            $labels[] = $day->format('d M');
            $data[] = (int) $reducer($byDay->get($day->toDateString(), collect()));
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
