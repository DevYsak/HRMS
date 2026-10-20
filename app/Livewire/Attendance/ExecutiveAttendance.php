<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Office;
use App\Models\PublicHoliday;
use App\Services\Attendance\AttendanceRateService;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Security\PeopleScope;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Executive Attendance Dashboard — company / branch / department attendance
 * KPIs, trend, risk, availability, forecast, AI insights and top/bottom
 * performers. Every metric recomputes from the selected filter (period,
 * department, office); the attendance score is the engine's persisted daily
 * score (Rule 11), not a heuristic. Single-pass aggregation, no per-dept N+1.
 */
class ExecutiveAttendance extends Component
{
    #[Url]
    public string $period = 'this_month';

    #[Url]
    public ?string $rangeFrom = null;

    #[Url]
    public ?string $rangeTo = null;

    #[Url]
    public ?int $departmentId = null;

    #[Url]
    public ?int $officeId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);
    }

    public function updated(): void
    {
        // Any filter change recomputes on the next render — no cached values.
    }

    public function resetFilters(): void
    {
        $this->reset(['period', 'rangeFrom', 'rangeTo', 'departmentId', 'officeId']);
        $this->period = 'this_month';
    }

    /**
     * The [start, end] the current period resolves to (end capped at today).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodRange(): array
    {
        $today = Carbon::today();
        [$start, $end] = match ($this->period) {
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [$today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter()],
            'year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
            'custom' => [
                Carbon::parse($this->rangeFrom ?: $today->copy()->startOfMonth()),
                Carbon::parse($this->rangeTo ?: $today),
            ],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        };

        return [$start->startOfDay(), $end->endOfDay()->min($today->copy()->endOfDay())];
    }

    /** Working days (Mon–Sat, minus public holidays) between two dates, inclusive. */
    protected function workingDays(Carbon $from, Carbon $to, array $holidayKeys): int
    {
        $days = 0;
        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $d) {
            if (! app(WorkingDayResolver::class)->isWeeklyOff($d) && ! isset($holidayKeys[$d->toDateString()])) {
                $days++;
            }
        }

        return max(1, $days);
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $today = Carbon::today();
        [$rangeStart, $rangeEnd] = $this->periodRange();

        // ── Reference data, scoped by the department/office filters ───────────
        // …and by the viewer's reach: a manager's "executive view" is their
        // reporting line, not the company's named performers.
        $reach = Auth::user()->accessibleEmployeeIds();

        $employees = Employee::where('status', 'active')
            ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))
            ->when($this->departmentId, fn ($q) => $q->where('department_id', $this->departmentId))
            ->when($this->officeId, fn ($q) => $q->where('office_id', $this->officeId))
            ->with(['user:id,name', 'department:id,name', 'office', 'exitRecord'])
            ->get();
        $activeCount = $employees->count();
        $empById = $employees->keyBy('id');
        $employeeIds = $employees->pluck('id')->all();

        $holidayKeys = PublicHoliday::whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->pluck('date')->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])->all();

        $rangeWorkingDays = $this->workingDays($rangeStart, $rangeEnd, $holidayKeys);
        // "Month" labels kept for the header; reflect the selected range now.
        $monthWorkingDays = $rangeWorkingDays;
        $elapsedWorkingDays = $rangeWorkingDays;

        // ── Attendance across the selected range (single query) ───────────────
        $range = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['employee_id', 'date', 'check_in', 'is_late', 'work_mode', 'total_hours']);
        $presentRecords = $range->whereNotNull('check_in');

        // The one attendance % (QA-F-079): attended ÷ eligible days, per
        // employee and summed for every group (company, department, branch).
        $rates = app(AttendanceRateService::class)->forEmployees($employees, $rangeStart, $rangeEnd->copy()->min($today));
        $rateOf = fn ($group) => AttendanceRateService::combine($group->map(fn ($e) => $rates['employees'][$e->id]));
        $companyPct = $rates['total']['exact'] ?? 0;
        $latePct = $presentRecords->count() > 0
            ? round($presentRecords->where('is_late', true)->count() / $presentRecords->count() * 100, 1)
            : 0;
        $absentPct = round(max(0, 100 - $companyPct), 1);

        // ── Engine attendance score (Rule 11): avg persisted daily score ──────
        $scoreRows = AttendanceDailyScore::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['employee_id', 'score']);
        $attendanceScore = $scoreRows->isNotEmpty() ? (int) round($scoreRows->avg('score')) : 0;
        $scoreByEmployee = $scoreRows->groupBy('employee_id')->map(fn ($g) => round($g->avg('score'), 1));

        // ── Today snapshot / workforce availability (genuinely live) ──────────
        $presentToday = $range->filter(fn ($a) => $a->check_in && Carbon::parse($a->date)->isToday());
        $onLeaveToday = LeaveRequest::whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->count();
        $wfhToday = $presentToday->whereIn('work_mode', ['wfh', 'hybrid'])->count();
        $availability = $activeCount > 0 ? round($presentToday->count() / $activeCount * 100) : 0;

        // ── Per-employee attendance % (for performers + risk) ─────────────────
        // Ranked only where a rate exists (no attendance recorded = no rank).
        $perfRows = $employees->filter(fn ($e) => $rates['employees'][$e->id]['percent'] !== null)->map(function ($e) use ($rates, $scoreByEmployee) {
            return [
                'name' => $e->user?->name ?? '—',
                'dept' => $e->department?->name ?? 'Unassigned',
                'pct' => $rates['employees'][$e->id]['percent'],
                'score' => $scoreByEmployee[$e->id] ?? null,
            ];
        })->sortByDesc('pct')->values();

        $topPerformers = $perfRows->take(5)->all();
        $bottomPerformers = $perfRows->reverse()->take(5)->values()->all();

        // ── Department attendance + engine score (grouped in-memory) ──────────
        $deptScore = $scoreRows->groupBy(fn ($r) => $empById[$r->employee_id]?->department_id)
            ->map(fn ($g) => round($g->avg('score')));
        $departments = $employees->groupBy('department_id')->map(function ($emps) use ($deptScore, $rateOf) {
            $deptId = $emps->first()->department_id;

            return [
                'name' => $emps->first()->department?->name ?? 'Unassigned',
                'headcount' => $emps->count(),
                'pct' => $rateOf($emps)['percent'] ?? 0,
                'score' => (int) ($deptScore[$deptId] ?? 0),
            ];
        })->sortByDesc('pct')->values();

        // ── Branch / office attendance ────────────────────────────────────────
        $branches = $employees->groupBy('office_id')->map(function ($emps) use ($rateOf) {
            return [
                'name' => $emps->first()->office?->name ?? 'Head Office',
                'headcount' => $emps->count(),
                'pct' => $rateOf($emps)['percent'] ?? 0,
            ];
        })->sortByDesc('pct')->values();

        // ── 6-month trend ending at the range end (grouped query) ─────────────
        $trendStart = $rangeEnd->copy()->startOfMonth()->subMonths(5);
        // Each month's company rate: AttendanceRateService over the selection.
        $rateService = app(AttendanceRateService::class);
        $trend = [];
        for ($m = $trendStart->copy(); $m->lte($rangeEnd->copy()->startOfMonth()); $m = $m->copy()->addMonth()) {
            $trend[] = [
                'label' => $m->format('M'),
                'pct' => $rateService->forEmployees($employees, $m->copy()->startOfMonth(), $m->copy()->endOfMonth()->min($today))['total']['percent'] ?? 0,
            ];
        }

        // ── Forecast + risk ───────────────────────────────────────────────────
        $forecast = min(100, (int) round($companyPct));
        $risk = $departments->filter(fn ($d) => $d['pct'] < 80)->values()->all();

        // ── AI insights (rule-based, real numbers, engine-scored) ─────────────
        $insights = [];
        $insights[] = ['good' => $companyPct >= 90, 'text' => $companyPct >= 90
            ? 'Company attendance is strong at '.$companyPct.'%'
            : 'Company attendance is '.$companyPct.'% — below the 90% target'];
        if ($attendanceScore > 0) {
            $insights[] = ['good' => $attendanceScore >= 85, 'text' => 'Engine attendance score averages '.$attendanceScore.'/100 across the selection'];
        }
        if (! empty($departments->first())) {
            $insights[] = ['good' => true, 'text' => 'Top department: '.$departments->first()['name'].' at '.$departments->first()['pct'].'%'];
        }
        if (! empty($risk)) {
            $insights[] = ['good' => false, 'text' => count($risk).' department(s) below 80% attendance need attention'];
        }
        $insights[] = ['good' => $latePct <= 10, 'text' => 'Late arrivals at '.$latePct.'% of present days'];

        return view('livewire.attendance.executive-attendance', [
            'activeCount' => $activeCount,
            'companyPct' => $companyPct,
            'latePct' => $latePct,
            'absentPct' => $absentPct,
            'attendanceScore' => $attendanceScore,
            'forecast' => $forecast,
            'availability' => $availability,
            'presentToday' => $presentToday->count(),
            'wfhToday' => $wfhToday,
            'onLeaveToday' => $onLeaveToday,
            'monthWorkingDays' => $monthWorkingDays,
            'elapsedWorkingDays' => $elapsedWorkingDays,
            'departments' => $departments->all(),
            'branches' => $branches->all(),
            'trend' => $trend,
            'topPerformers' => $topPerformers,
            'bottomPerformers' => $bottomPerformers,
            'risk' => $risk,
            'insights' => $insights,
            'rangeLabel' => $rangeStart->format('d M').' – '.$rangeEnd->format('d M Y'),
            'departmentOptions' => app(PeopleScope::class)->departmentOptions(Auth::user(), Auth::user()->hasPermission('view_attendance') ? 'view_attendance' : 'approve_leave')->pluck('name', 'id')->all(),
            'officeOptions' => Office::orderBy('name')->pluck('name', 'id')->all(),
        ])->layout('layouts.app', ['title' => 'Executive Attendance']);
    }
}
