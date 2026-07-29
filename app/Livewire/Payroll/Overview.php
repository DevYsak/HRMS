<?php

namespace App\Livewire\Payroll;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollRunFailure;
use App\Models\Payslip;
use App\Models\SalaryCycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Overview extends Component
{
    public string $filterYear = '';

    public function render()
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        $now = Carbon::now();
        $year = $this->filterYear !== '' ? (int) $this->filterYear : $now->year;
        $lastMonth = $now->copy()->subMonth();

        $recentPayrolls = Payroll::query()
            ->when($this->filterYear !== '', fn ($q) => $q->where('year', $this->filterYear))
            ->withCount('payslips')
            ->latest('created_at')
            ->take(10)
            ->get();

        $thisMonthPayout = Payroll::where('month', $now->format('F'))->where('year', $now->year)->sum('total_payout');
        $lastMonthPayout = Payroll::where('month', $lastMonth->format('F'))->where('year', $lastMonth->year)->sum('total_payout');
        $ytdPayout = Payroll::where('year', $now->year)->sum('total_payout');

        $yearScoped = Payroll::where('year', $year);
        $statusCounts = (clone $yearScoped)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $totalEmployees = Employee::where('status', 'active')->count();
        $processedCount = (int) ($statusCounts['finalized'] ?? 0);
        $pendingCount = (int) ($statusCounts['pending_finance'] ?? 0);
        $draftCount = (int) ($statusCounts['draft'] ?? 0);
        $failedCount = PayrollRunFailure::whereYear('created_at', $year)->count();
        $totalSalaryPaid = (float) Payslip::whereHas('payroll', fn ($q) => $q->where('year', $year)->where('status', 'finalized'))->sum('net_salary');

        // Last-6-month payout series (₹ lakh) for the trend chart — same
        // pattern already shipped in the executive dashboard's Payroll Analytics tile.
        $cats = [];
        $series = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $cats[] = $m->format('M');
            $series[] = round((float) Payroll::where('month', $m->format('F'))->where('year', $m->year)->sum('total_payout') / 100000, 2);
        }
        $payoutChart = [
            'chart' => ['type' => 'area', 'height' => 250, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 700]],
            'colors' => ['#F97316'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
            'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.35, 'opacityTo' => 0, 'stops' => [0, 90]]],
            'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 5],
            'xaxis' => ['categories' => $cats, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]],
            'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
            'tooltip' => ['theme' => 'light'],
            'series' => [['name' => '₹ Lakh', 'data' => $series]],
        ];

        // Upcoming cycle — next pay date for each active cycle, soonest first.
        // Wires salary_cycles into something real; the admin screen previously
        // wrote to a table nothing ever read.
        $upcomingCycles = SalaryCycle::where('is_active', true)->orderBy('pay_day')->get()->map(function ($cycle) use ($now) {
            $payDay = min((int) $cycle->pay_day, $now->copy()->endOfMonth()->day);
            $next = $now->copy()->startOfDay()->day($payDay);
            if ($next->lt($now->copy()->startOfDay())) {
                $next->addMonthNoOverflow();
            }

            return ['name' => $cycle->name, 'pay_day' => $cycle->pay_day, 'next' => $next, 'days' => (int) $now->copy()->startOfDay()->diffInDays($next, false)];
        })->sortBy('next')->values();

        // Recent Payroll Activity — same AuditLog-feed pattern already used on
        // the Super Admin / HR Admin / Executive dashboards, scoped to payroll.
        $recentActivity = AuditLog::with('user')
            ->whereIn('auditable_type', [Payroll::class, Payslip::class])
            ->latest('id')
            ->take(8)
            ->get();

        // Simple month-grid calendar marking each active cycle's pay day —
        // no calendar/heatmap component exists anywhere in the app, so this
        // matches the codebase's convention of inline custom markup rather
        // than pulling in a new frontend dependency.
        $calendarDays = collect(range(1, $now->daysInMonth))->map(function ($day) use ($now, $upcomingCycles) {
            $date = $now->copy()->startOfMonth()->addDays($day - 1);
            $cyclesToday = $upcomingCycles->filter(fn ($c) => (int) $c['pay_day'] === $day || ($day === $now->daysInMonth && (int) $c['pay_day'] > $now->daysInMonth));

            return ['day' => $day, 'is_today' => $date->isToday(), 'is_weekend' => $date->isWeekend(), 'cycles' => $cyclesToday->pluck('name')->all()];
        });

        return view('livewire.payroll.overview', [
            'recentPayrolls' => $recentPayrolls,
            'totalMonthlyPayout' => $thisMonthPayout,
            'lastMonthPayout' => $lastMonthPayout,
            'ytdPayout' => $ytdPayout,
            'statusCounts' => $statusCounts,
            'totalEmployees' => $totalEmployees,
            'processedCount' => $processedCount,
            'pendingCount' => $pendingCount,
            'draftCount' => $draftCount,
            'failedCount' => $failedCount,
            'totalSalaryPaid' => $totalSalaryPaid,
            'payoutChart' => $payoutChart,
            'upcomingCycles' => $upcomingCycles,
            'recentActivity' => $recentActivity,
            'calendarDays' => $calendarDays,
            'calendarMonthLabel' => $now->format('F Y'),
        ])->layout('layouts.app', ['title' => 'Payroll Overview']);
    }
}
