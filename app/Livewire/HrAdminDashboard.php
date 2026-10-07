<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OnboardingTask;
use App\Models\Payroll;
use App\Services\Attendance\AttendanceStatusResolver;
use Illuminate\Support\Carbon;
use Livewire\Component;

class HrAdminDashboard extends Component
{
    public function render()
    {
        $today = Carbon::today();
        $month = $today->month;
        $year = $today->year;

        // --- Headcount ---
        $totalActive = Employee::where('status', 'active')->count();
        $onboarding = Employee::where('status', 'onboarding')->count();
        $probation = Employee::where('status', 'probation')->count();
        $newThisMonth = Employee::whereMonth('joining_date', $month)->whereYear('joining_date', $year)->count();

        // --- Attendance Exceptions ---
        // Missing only once the shift window is over (shift end + 1h) — someone
        // still at work is not a missing checkout. The shared status decides.
        $resolver = app(AttendanceStatusResolver::class);
        $missingCheckout = Attendance::with('employee')->where('date', $today)->whereNull('check_out')->whereNotNull('check_in')->get()
            ->filter(fn (Attendance $a) => $a->employee && $resolver->forAttendance($a)['state'] === AttendanceStatusResolver::MISSING_CHECKOUT)
            ->count();
        $lateToday = Attendance::where('date', $today)->where('is_late', true)->count();
        $pendingReg = AttendanceRegularisation::where('status', 'pending')->count();

        // --- Leave Exceptions ---
        $pendingLeaves = LeaveRequest::whereIn('status', ['pending', 'pending_hr'])->count();
        $escalatedLeaves = LeaveRequest::where('status', 'escalated')->count();

        // --- Payroll ---
        $cycleARun = Payroll::where('cycle', 'cycle_a')->whereYear('created_at', $year)->whereMonth('created_at', $month)->first();
        $cycleBRun = Payroll::where('cycle', 'cycle_b')->whereYear('created_at', $year)->whereMonth('created_at', $month)->first();

        // --- Department Headcount ---
        $deptBreakdown = Department::withCount(['employees as active_count' => function ($q) {
            $q->where('status', 'active');
        }])->get();

        // --- Offboarding ---
        $pendingExits = Employee::whereHas('exitRecord', function ($q) use ($today) {
            $q->where('last_working_day', '>=', $today);
        })->where('status', '!=', 'inactive')->count();

        $pendingClearances = OnboardingTask::where('phase', 'offboarding')
            ->where('is_completed', false)
            ->count();

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
            'pendingExits',
            'pendingClearances',
            'recentAudit',
        ))->layout('layouts.app', ['title' => 'HR Admin Dashboard']);
    }
}
