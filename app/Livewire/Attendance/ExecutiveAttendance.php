<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Executive Attendance Dashboard — company / branch / department attendance
 * KPIs, trend, risk, availability, a simple month-end forecast, AI insights
 * and top/bottom performers. KPI-only, minimal UI, single-pass aggregation
 * (no per-department N+1) so it loads fast even company-wide.
 */
class ExecutiveAttendance extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);
    }

    /** Working days (Mon–Sat, minus public holidays) between two dates, inclusive. */
    protected function workingDays(Carbon $from, Carbon $to, array $holidayKeys): int
    {
        $days = 0;
        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $d) {
            if (! $d->isSunday() && ! isset($holidayKeys[$d->toDateString()])) {
                $days++;
            }
        }

        return max(1, $days);
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        // ── Reference data (loaded once) ──────────────────────────────────────
        $employees = Employee::where('status', 'active')
            ->with(['user:id,name', 'department:id,name', 'office:id,name'])
            ->get(['id', 'user_id', 'department_id', 'office_id']);
        $activeCount = $employees->count();
        $empById = $employees->keyBy('id');

        $holidayKeys = PublicHoliday::whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->pluck('date')->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])->all();

        $elapsedWorkingDays = $this->workingDays($monthStart, $today, $holidayKeys);
        $monthWorkingDays = $this->workingDays($monthStart, $monthEnd, $holidayKeys);

        // ── Month-to-date attendance (single query) ───────────────────────────
        $mtd = Attendance::whereBetween('date', [$monthStart->toDateString(), $today->toDateString()])
            ->get(['employee_id', 'date', 'check_in', 'is_late', 'work_mode', 'total_hours']);
        $presentRecords = $mtd->whereNotNull('check_in');

        $expected = max(1, $activeCount * $elapsedWorkingDays);
        $companyPct = min(100, round($presentRecords->count() / $expected * 100, 1));
        $latePct = $presentRecords->count() > 0
            ? round($presentRecords->where('is_late', true)->count() / $presentRecords->count() * 100, 1)
            : 0;
        $absentPct = round(max(0, 100 - $companyPct), 1);

        // ── Today snapshot / workforce availability ───────────────────────────
        $presentToday = $mtd->filter(fn ($a) => $a->check_in && Carbon::parse($a->date)->isToday());
        $onLeaveToday = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->count();
        $wfhToday = $presentToday->whereIn('work_mode', ['wfh', 'hybrid'])->count();
        $availability = $activeCount > 0 ? round($presentToday->count() / $activeCount * 100) : 0;

        // ── Per-employee attendance % (for performers + risk) ─────────────────
        $byEmployee = $presentRecords->groupBy('employee_id')->map(fn ($g) => $g->count());
        $perfRows = $employees->map(function ($e) use ($byEmployee, $elapsedWorkingDays) {
            $present = (int) ($byEmployee[$e->id] ?? 0);

            return [
                'name' => $e->user?->name ?? '—',
                'dept' => $e->department?->name ?? 'Unassigned',
                'pct' => min(100, (int) round($present / $elapsedWorkingDays * 100)),
            ];
        })->sortByDesc('pct')->values();

        $topPerformers = $perfRows->take(5)->all();
        $bottomPerformers = $perfRows->reverse()->take(5)->values()->all();

        // ── Department attendance (grouped in-memory) ─────────────────────────
        $deptStats = $presentRecords->groupBy(fn ($a) => $empById[$a->employee_id]?->department_id)
            ->map(fn ($g) => $g->count());
        $departments = $employees->groupBy('department_id')->map(function ($emps) use ($deptStats, $elapsedWorkingDays) {
            $name = $emps->first()->department?->name ?? 'Unassigned';
            $present = (int) ($deptStats[$emps->first()->department_id] ?? 0);
            $expected = max(1, $emps->count() * $elapsedWorkingDays);

            return [
                'name' => $name,
                'headcount' => $emps->count(),
                'pct' => min(100, (int) round($present / $expected * 100)),
            ];
        })->sortByDesc('pct')->values();

        // ── Branch / office attendance ────────────────────────────────────────
        $officeStats = $presentRecords->groupBy(fn ($a) => $empById[$a->employee_id]?->office_id)
            ->map(fn ($g) => $g->count());
        $branches = $employees->groupBy('office_id')->map(function ($emps) use ($officeStats, $elapsedWorkingDays) {
            $present = (int) ($officeStats[$emps->first()->office_id] ?? 0);
            $expected = max(1, $emps->count() * $elapsedWorkingDays);

            return [
                'name' => $emps->first()->office?->name ?? 'Head Office',
                'headcount' => $emps->count(),
                'pct' => min(100, (int) round($present / $expected * 100)),
            ];
        })->sortByDesc('pct')->values();

        // ── 6-month trend (grouped query) ─────────────────────────────────────
        $trendStart = $monthStart->copy()->subMonths(5);
        $trendRaw = Attendance::whereBetween('date', [$trendStart->toDateString(), $monthEnd->toDateString()])
            ->whereNotNull('check_in')
            ->get(['date'])
            ->groupBy(fn ($a) => Carbon::parse($a->date)->format('Y-m'))
            ->map->count();
        $trend = [];
        for ($m = $trendStart->copy(); $m->lte($monthStart); $m->addMonth()) {
            $key = $m->format('Y-m');
            $mEnd = $m->copy()->endOfMonth()->min($today);
            $wd = $this->workingDays($m->copy()->startOfMonth(), $mEnd, $holidayKeys);
            $exp = max(1, $activeCount * $wd);
            $trend[] = [
                'label' => $m->format('M'),
                'pct' => min(100, (int) round(((int) ($trendRaw[$key] ?? 0)) / $exp * 100)),
            ];
        }

        // ── Forecast (linear projection to month end) ─────────────────────────
        $forecast = min(100, (int) round($companyPct)); // MTD rate held to month end
        $attendanceScore = (int) round($companyPct * 0.7 + max(0, 100 - $latePct) * 0.3);

        // ── Attendance risk (departments below 80%) ───────────────────────────
        $risk = $departments->filter(fn ($d) => $d['pct'] < 80)->values()->all();

        // ── AI insights (rule-based, real numbers) ────────────────────────────
        $insights = [];
        $insights[] = ['good' => $companyPct >= 90, 'text' => $companyPct >= 90
            ? 'Company attendance is strong at '.$companyPct.'%'
            : 'Company attendance is '.$companyPct.'% — below the 90% target'];
        if (! empty($departments->first())) {
            $insights[] = ['good' => true, 'text' => 'Top department: '.$departments->first()['name'].' at '.$departments->first()['pct'].'%'];
        }
        if (! empty($risk)) {
            $insights[] = ['good' => false, 'text' => count($risk).' department(s) below 80% attendance need attention'];
        }
        $insights[] = ['good' => $latePct <= 10, 'text' => 'Late arrivals at '.$latePct.'% of present days'];
        $insights[] = ['good' => $availability >= 85, 'text' => 'Workforce availability today: '.$availability.'%'];

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
        ])->layout('layouts.app', ['title' => 'Executive Attendance']);
    }
}
