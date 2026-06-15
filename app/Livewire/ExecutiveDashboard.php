<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OnboardingTask;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PipRecord;
use App\Models\PromotionRecommendation;
use App\Models\WarningLetter;
use Illuminate\Support\Carbon;
use Livewire\Component;

class ExecutiveDashboard extends Component
{
    public function render()
    {
        $today = Carbon::today();
        $month = $today->month;
        $year = $today->year;

        // --- Headcount ---
        $activeCount = Employee::where('status', 'active')->count();
        $onboardingCount = Employee::where('status', 'onboarding')->count();
        $probationCount = Employee::where('status', 'probation')->count();

        // --- Today's Attendance ---
        $presentToday = Attendance::where('date', $today->toDateString())
            ->whereNotNull('check_in')
            ->count();

        $lateToday = Attendance::where('date', $today->toDateString())
            ->where('is_late', true)
            ->count();

        // --- Pending Approvals (company-wide) ---
        $pendingLeaves = LeaveRequest::where('status', 'pending')->count();
        $pendingOt = OtRequest::where('status', 'pending')->count();

        // --- Payroll Status ---
        $cycleAPayroll = Payroll::where('cycle', 'cycle_a')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->first();

        $cycleBPayroll = Payroll::where('cycle', 'cycle_b')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->first();

        // --- Performance (latest open cycle avg rating) ---
        $avgRating = PerformanceReview::where('status', 'submitted')
            ->whereYear('submitted_at', $year)
            ->avg('overall_rating');

        // --- Attendance % today ---
        $attendancePercent = $activeCount > 0 ? (int) round($presentToday / $activeCount * 100) : 0;

        // --- Leave analytics ---
        $onLeaveToday = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->count();

        // --- Attrition (snapshot: separated vs total workforce) ---
        $separatedCount = Employee::whereIn('status', ['resigned', 'terminated'])->count();
        $attritionRate = ($activeCount + $separatedCount) > 0
            ? round($separatedCount / ($activeCount + $separatedCount) * 100, 1)
            : 0;

        // --- Promotion pipeline (in-review recommendations) ---
        $promotionPipeline = PromotionRecommendation::whereIn('status', [
            'pending_hr', 'pending_dept_head', 'pending_super_admin',
        ])->count();

        // --- Warnings & PIP ---
        $activeWarnings = WarningLetter::whereIn('status', ['issued', 'acknowledged', 'under_review'])->count();
        $onPip = PipRecord::whereIn('status', ['active', 'under_review', 'extended'])->count();

        // --- Alerts ---
        $expiringDocs = Document::query()->expiringSoon(30)->count();
        $probationDue = Employee::where('status', 'probation')
            ->whereNotNull('probation_end_date')
            ->whereDate('probation_end_date', '<=', $today->copy()->addDays(30))
            ->count();
        $overdueOnboarding = OnboardingTask::where('phase', 'onboarding')
            ->where('is_completed', false)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->count();

        // --- Department health (headcount + today's attendance rate) ---
        $deptHealth = Employee::with('department')
            ->where('status', 'active')
            ->get()
            ->groupBy('department.name')
            ->map(function ($emps, $name) use ($today) {
                $present = Attendance::whereIn('employee_id', $emps->pluck('id'))
                    ->where('date', $today->toDateString())
                    ->whereNotNull('check_in')
                    ->count();
                $head = $emps->count();

                return [
                    'name' => $name ?: 'Unassigned',
                    'headcount' => $head,
                    'health' => $head > 0 ? (int) round($present / $head * 100) : 0,
                ];
            })
            ->sortByDesc('headcount')
            ->values();

        // --- Department headcount breakdown (kept for compatibility) ---
        $byDepartment = $deptHealth->mapWithKeys(fn ($d) => [$d['name'] => $d['headcount']]);

        // --- Performance trend (avg score across recent cycles) ---
        $perfTrend = PerformanceCycle::whereIn('status', ['active', 'completed', 'locked'])
            ->latest('start_date')
            ->limit(5)
            ->with('scorecards')
            ->get()
            ->reverse()
            ->map(fn ($cycle) => [
                'name' => $cycle->name,
                'avg' => round($cycle->scorecards->avg('final_score') ?? 0, 1),
            ])
            ->values();

        return view('livewire.executive-dashboard', compact(
            'activeCount',
            'onboardingCount',
            'probationCount',
            'presentToday',
            'lateToday',
            'attendancePercent',
            'onLeaveToday',
            'pendingLeaves',
            'pendingOt',
            'separatedCount',
            'attritionRate',
            'promotionPipeline',
            'activeWarnings',
            'onPip',
            'expiringDocs',
            'probationDue',
            'overdueOnboarding',
            'cycleAPayroll',
            'cycleBPayroll',
            'avgRating',
            'byDepartment',
            'deptHealth',
            'perfTrend',
        ))->layout('layouts.app', ['title' => 'Executive Dashboard']);
    }
}
