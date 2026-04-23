<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use Illuminate\Support\Carbon;
use Livewire\Component;

class HrAdminDashboard extends Component
{
    public function render()
    {
        $today = Carbon::today();
        $month = $today->month;
        $year  = $today->year;

        // --- Headcount ---
        $totalActive     = Employee::where('status', 'active')->count();
        $onboarding      = Employee::where('status', 'onboarding')->count();
        $probation       = Employee::where('status', 'probation')->count();
        $newThisMonth    = Employee::whereMonth('joining_date', $month)->whereYear('joining_date', $year)->count();

        // --- Attendance Exceptions ---
        $missingCheckout = Attendance::where('date', $today)->whereNull('check_out')->whereNotNull('check_in')->count();
        $lateToday       = Attendance::where('date', $today)->where('is_late', true)->count();
        $pendingReg      = AttendanceRegularisation::where('status', 'pending')->count();

        // --- Leave Exceptions ---
        $pendingLeaves   = LeaveRequest::where('status', 'pending')->count();
        $escalatedLeaves = LeaveRequest::where('status', 'escalated')->count();

        // --- Payroll ---
        $cycleARun = Payroll::where('cycle', 'cycle_a')->whereYear('created_at', $year)->whereMonth('created_at', $month)->first();
        $cycleBRun = Payroll::where('cycle', 'cycle_b')->whereYear('created_at', $year)->whereMonth('created_at', $month)->first();

        // --- Department Headcount ---
        $deptBreakdown = Department::withCount(['employees as active_count' => function ($q) {
            $q->where('status', 'active');
        }])->get();

        // --- Recent Audit Logs ---
        $recentAudit = AuditLog::with('user')->orderByDesc('id')->take(6)->get();

        return view('livewire.hr-admin-dashboard', compact(
            'totalActive',
            'onboarding',
            'probation',
            'newThisMonth',
            'missingCheckout',
            'lateToday',
            'pendingReg',
            'pendingLeaves',
            'escalatedLeaves',
            'cycleARun',
            'cycleBRun',
            'deptBreakdown',
            'recentAudit',
        ))->layout('layouts.app', ['title' => 'HR Admin Dashboard']);
    }
}
