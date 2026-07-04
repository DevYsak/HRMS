<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRecord;
use App\Models\PublicHoliday;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
        'muster' => 'Muster Roll',
        'late' => 'Late Report',
        'absent' => 'Absent Report',
        'holiday' => 'Holiday Report',
        'leave' => 'Leave Report',
        'overtime' => 'Overtime Report',
        'working_hours' => 'Working Hours Report',
        'department' => 'Department Report',
        'employee' => 'Employee Report',
        'biometric' => 'Biometric Report',
        'regularization' => 'Regularization Report',
    ];

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
            'muster' => $this->muster($from, $to, $filters),
            'late' => $this->late($from, $to, $filters),
            'absent' => $this->absent($from, $to, $filters),
            'holiday' => $this->holiday($from, $to),
            'leave' => $this->leave($from, $to, $filters),
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
        $workDays = max(1, (int) $from->copy()->startOfDay()->diffInDaysFiltered(fn ($d) => ! $d->isSunday(), $to));

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
                    $day->isSunday() => 'W',
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
                if ($day->isSunday() || isset($holidays[$key]) || isset($present[$key])) {
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
