<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\PerformanceReview;
use Illuminate\Support\Carbon;
use Livewire\Component;

class ExecutiveDashboard extends Component
{
    public function render()
    {
        $today = Carbon::today();
        $month = $today->month;
        $year  = $today->year;

        // --- Headcount ---
        $activeCount      = Employee::where('status', 'active')->count();
        $onboardingCount  = Employee::where('status', 'onboarding')->count();
        $probationCount   = Employee::where('status', 'probation')->count();

        // --- Today's Attendance ---
        $presentToday = Attendance::where('date', $today->toDateString())
            ->whereNotNull('check_in')
            ->count();

        $lateToday = Attendance::where('date', $today->toDateString())
            ->where('is_late', true)
            ->count();

        // --- Pending Approvals (company-wide) ---
        $pendingLeaves = LeaveRequest::where('status', 'pending')->count();
        $pendingOt     = OtRequest::where('status', 'pending')->count();

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

        // --- Department headcount breakdown ---
        $byDepartment = Employee::with('department')
            ->where('status', 'active')
            ->get()
            ->groupBy('department.name')
            ->map->count();

        return view('livewire.executive-dashboard', compact(
            'activeCount',
            'onboardingCount',
            'probationCount',
            'presentToday',
            'lateToday',
            'pendingLeaves',
            'pendingOt',
            'cycleAPayroll',
            'cycleBPayroll',
            'avgRating',
            'byDepartment',
        ))->layout('layouts.app', ['title' => 'Executive Dashboard']);
    }
}
