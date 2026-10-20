<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveYear;
use App\Models\OvertimeRecord;
use App\Models\PublicHoliday;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceRateService;
use App\Services\Attendance\AttendanceWindowService;
use App\Services\Attendance\WfhConsistencyService;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveYearResolver;
use App\Services\Payroll\PayableDaysService;
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
        'wfh_consistency' => 'WFH Consistency',
    ];

    /**
     * @param  array{from?:string,to?:string,department_id?:int|string|null,office_id?:int|string|null,shift_id?:int|string|null,employee_id?:int|string|null,employee_ids?:array<int,int>,mode?:string|null}  $filters  employee_ids = the viewer's reach (absent = company-wide)
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
            'wfh_consistency' => $this->wfhConsistency($from, $to, $filters),
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
                ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
                ->when(isset($filters['employee_ids']), fn ($q) => $q->whereIn('id', $filters['employee_ids']));
        });

        if ($hasMode && ! empty($filters['mode'])) {
            $query->where('work_mode', $filters['mode']);
        }
    }

    /**
     * Employee query narrowed by the placement filters (no attendance join).
     * activeOnly keeps the historical "status = active" filter for the reports
     * that rely on it; the window-based reports take every non-archived
     * employee and let the working-day rules decide employment.
     */
    protected function employeeQuery(array $filters, bool $activeOnly = true): Builder
    {
        return Employee::query()->whereHas('user')
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['office_id'] ?? null, fn ($q, $v) => $q->where('office_id', $v))
            ->when($filters['shift_id'] ?? null, fn ($q, $v) => $q->where('shift_id', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when(isset($filters['employee_ids']), fn ($q) => $q->whereIn('id', $filters['employee_ids']))
            ->when($activeOnly, fn ($q) => $q->where('status', 'active'), fn ($q) => $q->whereNotIn('status', ['draft', 'archived']));
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
            ($h = $this->worked($a)) > 0 ? number_format($h, 1).'h' : '—',
            $a->weeklyOffLabel() ?? ($a->is_late ? 'Late '.(int) ($a->late_minutes ?? 0).'m' : ucfirst($a->status ?? '—')),
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
        // Same classification as My Attendance and Employee 360
        // (AttendanceWindowService): absent = a past scheduled working day with
        // no attendance — never a holiday, weekly off or approved leave (P3-01;
        // it used to be weekdays − present). Work on a weekly off is its own
        // column and never offsets an absence.
        $window = app(AttendanceWindowService::class);
        $rates = app(AttendanceRateService::class);
        $mode = (string) ($filters['mode'] ?? '');

        $data = [];
        $totPresent = 0;
        $maxScheduled = 0;
        foreach ($this->employeeQuery($filters, activeOnly: false)->with(['user', 'department'])->orderBy('id')->get() as $emp) {
            $s = $window->summary($emp, $from->copy()->startOfDay(), $to->copy()->startOfDay(), $mode);
            if ($s['scheduled'] === 0 && ! $s['has_rows']) {
                continue;   // not employed in the range
            }
            // The one attendance % (QA-F-079) — whatever the mode filter narrows.
            $pct = $rates->forEmployee($emp, $from, $to)['percent'];
            $totPresent += $s['present'];
            $maxScheduled = max($maxScheduled, $s['scheduled']);
            $data[] = [
                $emp->employee_id ?? '—',
                $emp->user?->name ?? '—',
                $emp->department?->name ?? '—',
                $s['present'],
                $s['absent'],
                $s['late'],
                $s['weekly_off_worked'],
                number_format($s['minutes'] / 60, 1).'h',
                $pct === null ? '—' : $pct.'%',
            ];
        }

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Present', 'Absent', 'Late', 'Worked on Weekly Off', 'Total Hours', 'Attendance %'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Employees', 'value' => count($data)],
                ['label' => 'Scheduled Days', 'value' => $maxScheduled],
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

    /** Whether a date is a non-working day, per the configured working week. */
    protected function isWeeklyOff(Carbon $day): bool
    {
        return app(WorkingDayResolver::class)->isWeeklyOff($day);
    }

    /**
     * The day grid and payable-days figures for every employee in the
     * filter, from PayableDaysService — the same answer the payroll engine
     * and the payslip use (Phase 4), never a second formula.
     *
     * @return array{days: Collection, employees: Collection, payable: array<int, array<string, mixed>>}
     */
    protected function payableGrid(Carbon $from, Carbon $to, array $filters): array
    {
        $days = collect(CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()));
        $employees = $this->employeeQuery($filters, activeOnly: false)
            ->with('user', 'department', 'exitRecord')
            ->get()
            ->sortBy(fn (Employee $e) => $e->user?->name ?? '')
            ->values();

        $service = app(PayableDaysService::class);
        $payable = [];
        foreach ($employees as $employee) {
            $payable[$employee->id] = $service->forPeriod($employee, $from->copy()->startOfDay(), $to->copy()->startOfDay());
        }

        return ['days' => $days, 'employees' => $employees, 'payable' => $payable];
    }

    /**
     * Monthly Attendance Register — the day grid plus trailing totals, in the
     * shape HR's own sheet uses so the two can be diffed column for column.
     */
    protected function register(Carbon $from, Carbon $to, array $filters): array
    {
        ['days' => $days, 'employees' => $employees, 'payable' => $payable] = $this->payableGrid($from, $to, $filters);

        $columns = array_merge(
            ['Emp ID', 'Employee', 'Department'],
            $days->map(fn ($d) => $d->format('d'))->all(),
            ['P', 'A', 'L', 'HD', 'LV', 'UL', 'WO', 'H', 'LOP Days', 'Paid Days'],
        );

        $rows = [];
        foreach ($employees as $employee) {
            $p = $payable[$employee->id];
            $cells = array_map(fn ($d) => $d['code'], $p['days']);
            $tally = array_count_values($cells);

            $rows[] = array_merge(
                [
                    $employee->employee_id ?? '—',
                    $employee->user?->name ?? '—',
                    $employee->department?->name ?? '—',
                ],
                array_values($cells),
                [
                    // Late still counts as a present day.
                    ($tally[PayableDaysService::CODE_PRESENT] ?? 0) + ($tally[PayableDaysService::CODE_LATE] ?? 0),
                    $p['absent_days'],
                    $tally[PayableDaysService::CODE_LATE] ?? 0,
                    $tally[PayableDaysService::CODE_HALF_DAY] ?? 0,
                    $p['paid_leave_days'],
                    $p['unpaid_leave_days'],
                    $p['weekly_off_days'],
                    $p['holiday_days'],
                    $p['lop_days'],
                    $p['paid_days'],
                ],
            );
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'summary' => [
                ['label' => 'Employees', 'value' => $employees->count()],
                ['label' => 'Days', 'value' => $days->count()],
                ['label' => 'Legend', 'value' => collect(PayableDaysService::CODE_LABELS)->map(fn ($label, $code) => "{$code} {$label}")->implode(' · ')],
            ],
        ];
    }

    /**
     * Payroll Attendance Report — the bridge between attendance and payroll:
     * the eligible working days, the days paid and the days lost, exactly as
     * the payroll engine counts them (PayableDaysService).
     */
    protected function payrollAttendance(Carbon $from, Carbon $to, array $filters): array
    {
        ['employees' => $employees, 'payable' => $payable] = $this->payableGrid($from, $to, $filters);

        $rows = [];
        $totalPaid = 0.0;
        $totalLop = 0.0;
        $totalPending = 0.0;

        foreach ($employees as $employee) {
            $p = $payable[$employee->id];
            $totalPaid += $p['paid_days'];
            $totalLop += $p['lop_days'];
            $totalPending += $p['pending_days'];

            $rows[] = [
                $employee->employee_id ?? '—',
                $employee->user?->name ?? '—',
                $employee->department?->name ?? '—',
                $p['eligible_working_days'],
                $p['not_employed_working_days'],
                $p['present_days'],
                $p['paid_leave_days'],
                $p['unpaid_leave_days'],
                $p['absent_days'],
                $p['pending_days'],
                $p['weekly_off_days'],
                $p['holiday_days'],
                $p['lop_days'],
                $p['paid_days'],
            ];
        }

        return [
            'columns' => [
                'Emp ID', 'Employee', 'Department', 'Eligible Working Days', 'Not Employed', 'Present',
                'Paid Leave', 'Unpaid Leave', 'Absent (LOP)', 'Pending (not deducted)', 'Weekly Offs', 'Holidays', 'LOP Days', 'Paid Days',
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Employees', 'value' => $employees->count()],
                ['label' => 'Total Paid Days', 'value' => round($totalPaid, 2)],
                ['label' => 'Total LOP Days', 'value' => round($totalLop, 2)],
                ['label' => 'Pending (not deducted)', 'value' => round($totalPending, 2)],
            ],
        ];
    }

    /**
     * Leave Summary — the per-employee, per-type balance rollup. Distinct from
     * the `leave` report, which lists individual requests.
     */
    protected function leaveSummary(Carbon $from, Carbon $to, array $filters): array
    {
        // The leave year that contains the end of the range (1 July start by
        // default) — never the calendar year — and every figure from
        // LeaveBalanceCalculator, the source My Leave, Employee 360 and AI
        // use: accruals, adjustments and expiry included, carried-forward
        // counted once (P3-02).
        $leaveYear = app(LeaveYearResolver::class)->forDate($to);
        $calculator = app(LeaveBalanceCalculator::class);
        $employeeIds = $this->employeeQuery($filters)->pluck('id');

        $balances = $this->balancesForLeaveYear($employeeIds, $leaveYear)
            ->sortBy(fn (LeaveBalance $b) => [$b->employee?->user?->name ?? '', $b->leaveType?->name ?? ''])
            ->values();

        $rows = $balances->map(function (LeaveBalance $balance) use ($calculator) {
            $s = $calculator->summary($balance);

            return [
                $balance->employee?->employee_id ?? '—',
                $balance->employee?->user?->name ?? '—',
                $balance->employee?->department?->name ?? '—',
                $balance->leaveType?->name ?? '—',
                number_format($s['base'] + $s['opening'], 1),
                number_format($s['carry_forward'], 1),
                number_format($s['accrued'] + $s['add_on'], 1),
                number_format($s['adjustment_credit'] - $s['adjustment_debit'], 1),
                number_format($s['expired'], 1),
                number_format($s['used'], 1),
                number_format($s['encashed'], 1),
                number_format($s['approved_available'], 1),
                number_format($s['pending'], 1),
                number_format($s['available_to_request'], 1),
            ];
        })->all();

        return [
            'columns' => [
                'Emp ID', 'Employee', 'Department', 'Leave Type',
                'Opening / Entitlement', 'Carried Forward', 'Accrued / Earned', 'Adjustments (net)', 'Expired',
                'Availed', 'Encashed', 'Approved Balance', 'Pending', 'Available to Request',
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Leave year', 'value' => $leaveYear->label],
                ['label' => 'Employees', 'value' => $balances->pluck('employee_id')->unique()->count()],
                ['label' => 'Total Availed', 'value' => number_format((float) $balances->sum(fn ($b) => (float) $b->used_days), 1)],
            ],
        ];
    }

    /**
     * Balance rows of one leave year (by leave_year_id, or the legacy year
     * integer for rows not yet linked) — the lookup My Leave uses.
     *
     * @param  Collection<int, int>|array<int, int>  $employeeIds
     * @return Collection<int, LeaveBalance>
     */
    protected function balancesForLeaveYear(Collection|array $employeeIds, LeaveYear $leaveYear, ?string $category = null): Collection
    {
        return LeaveBalance::with(['employee.user', 'employee.department', 'leaveType'])
            ->whereIn('employee_id', $employeeIds)
            ->where(fn ($q) => $q->where('leave_year_id', $leaveYear->id)->orWhere(fn ($legacy) => $legacy->whereNull('leave_year_id')->where('year', $leaveYear->legacyYear())))
            ->when($category, fn ($q, $c) => $q->whereHas('leaveType', fn ($t) => $t->where('category', $c)))
            ->get();
    }

    /** Comp-Off Summary — credits earned against comp-off leave taken. */
    protected function compOff(Carbon $from, Carbon $to, array $filters): array
    {
        // Leave year, not calendar year; balance from the calculator (P3-02).
        $leaveYear = app(LeaveYearResolver::class)->forDate($to);
        $calculator = app(LeaveBalanceCalculator::class);
        $employeeIds = $this->employeeQuery($filters)->pluck('id');

        $balances = $this->balancesForLeaveYear($employeeIds, $leaveYear, 'comp_off')
            ->sortBy(fn (LeaveBalance $b) => $b->employee?->user?->name ?? '')
            ->values();

        $rows = $balances->map(function (LeaveBalance $balance) use ($calculator) {
            $s = $calculator->summary($balance);

            return [
                $balance->employee?->employee_id ?? '—',
                $balance->employee?->user?->name ?? '—',
                $balance->employee?->department?->name ?? '—',
                number_format((float) ($balance->comp_off_credits ?? 0), 1),
                number_format($s['used'], 1),
                number_format($s['pending'], 1),
                number_format($s['approved_available'], 1),
            ];
        })->all();

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Earned', 'Availed', 'Pending', 'Balance'],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Leave year', 'value' => $leaveYear->label],
                ['label' => 'Employees', 'value' => $balances->count()],
                ['label' => 'Total Earned', 'value' => number_format((float) $balances->sum(fn ($b) => (float) ($b->comp_off_credits ?? 0)), 1)],
                ['label' => 'Total Availed', 'value' => number_format((float) $balances->sum(fn ($b) => (float) $b->used_days), 1)],
            ],
        ];
    }

    protected function late(Carbon $from, Carbon $to, array $filters): array
    {
        // Late = the calculator's verdict (shift start + grace) through
        // AttendanceWindowService — what Team View, My Attendance and
        // Employee 360 count — not the stored is_late flag (P3-01).
        $window = app(AttendanceWindowService::class);
        $mode = (string) ($filters['mode'] ?? '');
        $late = collect();
        foreach ($this->employeeQuery($filters, activeOnly: false)->with(['user', 'department'])->orderBy('id')->get() as $emp) {
            foreach ($window->days($emp, $from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
                if ($day['status'] === 'late' && ($mode === '' || $day['mode'] === $mode)) {
                    $late->push(['employee' => $emp, 'day' => $day]);
                }
            }
        }
        $late = $late->sortBy(fn ($l) => [$l['day']['date'], $l['employee']->id])->values();
        $lateKeys = $late->mapWithKeys(fn ($l) => [$l['employee']->id.'|'.$l['day']['date'] => true])->all();
        $minutes = $late->sum(fn ($l) => $l['day']['late_minutes']);

        $data = $late->map(fn ($l) => [
            $l['employee']->employee_id ?? '—',
            $l['employee']->user?->name ?? '—',
            $l['employee']->department?->name ?? '—',
            Carbon::parse($l['day']['date'])->format('d M Y'),
            $l['day']['first_in_min'] !== null ? Carbon::parse($l['day']['date'])->startOfDay()->addMinutes($l['day']['first_in_min'])->format('h:i A') : '—',
            $l['day']['late_minutes'].'m',
        ])->all();

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Date', 'Check In', 'Late By'],
            'rows' => $data,
            'summary' => [
                ['label' => 'Late Instances', 'value' => $late->count()],
                ['label' => 'Total Late', 'value' => $this->hm((int) $minutes)],
                ['label' => 'Avg Late', 'value' => $late->count() ? round($minutes / $late->count()).'m' : '0m'],
            ],
            'trend' => $this->dailyTrend($from, $to, $filters, fn ($g) => $g->filter(fn ($a) => isset($lateKeys[$a->employee_id.'|'.$a->date->toDateString()]))->count()),
        ];
    }

    protected function absent(Carbon $from, Carbon $to, array $filters): array
    {
        // Every non-archived employee: the working-day rules decide employment
        // (status "active" alone left out probation and on-leave staff).
        $employees = $this->employeeQuery($filters, activeOnly: false)->with('user', 'department')->get();
        $att = Attendance::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('check_in')->get()
            ->groupBy('employee_id')
            ->map(fn ($g) => $g->keyBy(fn ($a) => $a->date->toDateString()));
        $data = [];
        $cutoff = min($to, Carbon::yesterday()->endOfDay());
        $days = app(WorkingDayResolver::class);
        foreach ($employees as $emp) {
            $present = $att->get($emp->id) ?? collect();
            // Pulse v3.1: weekly offs, the employee's own public holidays, MDL
            // shutdown, approved leave and days outside employment are never
            // absent — the same classification every screen uses.
            $states = $days->classifyRange($emp, $from->copy()->startOfDay(), Carbon::parse($cutoff));
            foreach (CarbonPeriod::create($from->copy()->startOfDay(), $cutoff) as $day) {
                $key = $day->toDateString();
                if (isset($present[$key]) || ($states[$key]['state'] ?? WorkingDayResolver::WORKING_DAY) !== WorkingDayResolver::WORKING_DAY) {
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
                ->when($filters['employee_id'] ?? null, fn ($x, $v) => $x->where('id', $v))
                ->when(isset($filters['employee_ids']), fn ($x) => $x->whereIn('id', $filters['employee_ids']));
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
        // Payable OT = an approved request measured from genuine attendance
        // (OvertimeService::hours()); unapproved records are never payable.
        $q = OvertimeRecord::with('employee.user', 'employee.department')
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('otRequest', fn ($r) => $r->where('status', 'approved'));
        $q->whereHas('employee', function (Builder $e) use ($filters) {
            $e->when($filters['department_id'] ?? null, fn ($x, $v) => $x->where('department_id', $v))
                ->when($filters['office_id'] ?? null, fn ($x, $v) => $x->where('office_id', $v))
                ->when($filters['employee_id'] ?? null, fn ($x, $v) => $x->where('id', $v))
                ->when(isset($filters['employee_ids']), fn ($x) => $x->whereIn('id', $filters['employee_ids']));
        });
        $rows = $q->orderBy('work_date')->get();

        $data = $rows->map(fn ($o) => [
            $o->employee?->employee_id ?? '—',
            $o->employee?->user?->name ?? '—',
            $o->employee?->department?->name ?? '—',
            Carbon::parse($o->work_date)->format('d M Y'),
            number_format((float) $o->ot_hours, 1).'h',
            $o->ot_amount ? number_format((float) $o->ot_amount, 2) : '—',
            match (true) {
                $o->is_paid => 'Paid',
                (float) $o->ot_hours <= 0 => 'Waiting for attendance',
                default => 'Payable — next payroll',
            },
        ])->all();

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Date', 'Payable OT Hours', 'Payable OT Amount', 'Payroll Status'],
            'rows' => $data,
            'summary' => [
                ['label' => 'OT Records', 'value' => $rows->count()],
                ['label' => 'Total Payable OT', 'value' => number_format((float) $rows->sum('ot_hours'), 1).'h'],
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
            $hours = $group->sum(fn ($a) => $this->worked($a));
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
                ['label' => 'Total Hours', 'value' => number_format($rows->flatten(1)->sum(fn ($a) => $this->worked($a)), 1).'h'],
            ],
        ];
    }

    protected function department(Carbon $from, Carbon $to, array $filters): array
    {
        $rows = $this->attendanceQuery($from, $to, $filters)->get()
            ->groupBy(fn ($a) => $a->employee?->department?->name ?? '—');

        // Department attendance % = its employees' attended ÷ eligible days
        // (AttendanceRateService, QA-F-079) — not present rows ÷ all rows.
        $rates = app(AttendanceRateService::class);
        $ratesByDept = [];
        foreach ($this->employeeQuery($filters, activeOnly: false)->with('department')->get() as $emp) {
            $ratesByDept[$emp->department?->name ?? '—'][] = $rates->forEmployee($emp, $from, $to);
        }

        $data = [];
        $trendLabels = [];
        $trendData = [];
        foreach ($rows as $dept => $group) {
            $present = $group->whereNotNull('check_in')->count();
            $late = $group->where('is_late', true)->count();
            $pct = AttendanceRateService::combine($ratesByDept[$dept] ?? [])['percent'];
            $data[] = [
                $dept,
                $group->pluck('employee_id')->unique()->count(),
                $present,
                $late,
                number_format($group->sum(fn ($a) => $this->worked($a)), 1).'h',
                $pct === null ? '—' : $pct.'%',
            ];
            $trendLabels[] = $dept;
            $trendData[] = $pct ?? 0;
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
            ($h = $this->worked($a)) > 0 ? number_format($h, 1).'h' : '—',
            (int) ($a->break_minutes ?? 0).'m',
            $a->weeklyOffLabel() ?? ($a->is_late ? 'Late '.(int) ($a->late_minutes ?? 0).'m' : ucfirst($a->status ?? '—')),
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
                ['label' => 'Total Hours', 'value' => number_format($rows->sum(fn ($a) => $this->worked($a)), 1).'h'],
            ],
            'trend' => $this->dailyTrend($from, $to, $filters),
        ];
    }

    protected function biometric(Carbon $from, Carbon $to, array $filters): array
    {
        $q = AttendanceDailySummary::with('employee.user')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            // The viewer's reach, as every other report type applies it.
            ->when(isset($filters['employee_ids']), fn ($x) => $x->whereIn('employee_id', $filters['employee_ids']));
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
                ->when($filters['employee_id'] ?? null, fn ($x, $v) => $x->where('id', $v))
                ->when(isset($filters['employee_ids']), fn ($x) => $x->whereIn('id', $filters['employee_ids']));
        });
        $rows = $q->orderByDesc('work_date')->get();

        $data = $rows->map(fn ($r) => [
            $r->employee?->user?->name ?? '—',
            Carbon::parse($r->work_date)->format('d M Y'),
            $r->requestedWindowLabel('h:i A'),
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
     * P3-07: approved WFH (the plan) against the recorded attendance mode,
     * one row per attended day where either says WFH. Read-only.
     */
    protected function wfhConsistency(Carbon $from, Carbon $to, array $filters): array
    {
        $service = app(WfhConsistencyService::class);
        $employees = $this->employeeQuery($filters, activeOnly: false)->with(['user', 'department'])->get()->keyBy('id');
        $days = $service->days($employees->keys()->all(), $from->copy()->startOfDay(), $to->copy()->startOfDay())
            ->when(! empty($filters['mode']), fn ($c) => $c->where('recorded_mode', $filters['mode']))
            ->values();
        $counts = $service->summary($days);

        $data = $days->map(function (array $day) use ($employees) {
            $employee = $employees->get($day['employee_id']);

            return [
                $employee?->employee_id ?? '—',
                $employee?->user?->name ?? '—',
                $employee?->department?->name ?? '—',
                Carbon::parse($day['date'])->format('d M Y'),
                $day['recorded_label'],
                $day['request_label'] ?? ($day['pending_request_id'] ? 'Pending #'.$day['pending_request_id'].' (not approved)' : 'None'),
                $day['label'],
            ];
        })->all();

        return [
            'columns' => ['Emp ID', 'Employee', 'Department', 'Date', 'Recorded Mode', 'WFH Request', 'Result'],
            'rows' => $data,
            'summary' => [
                ['label' => WfhConsistencyService::LABELS[WfhConsistencyService::VALID], 'value' => $counts[WfhConsistencyService::VALID]],
                ['label' => WfhConsistencyService::LABELS[WfhConsistencyService::APPROVED_NOT_WFH], 'value' => $counts[WfhConsistencyService::APPROVED_NOT_WFH]],
                ['label' => WfhConsistencyService::LABELS[WfhConsistencyService::UNAPPROVED_WFH], 'value' => $counts[WfhConsistencyService::UNAPPROVED_WFH]],
                ['label' => 'Employees to review', 'value' => $counts['employees_flagged']],
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

    /** Worked hours of a row — the canonical first-in → final-out (no break deduction). */
    private function worked(Attendance $attendance): float
    {
        return app(AttendanceCalculator::class)->workedHours($attendance);
    }
}
