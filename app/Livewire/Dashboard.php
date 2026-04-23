<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();
        $role = $user->role;

        // Super Admin / HR Admin → HR overview
        if ($role === UserRole::SuperAdmin || $role === UserRole::HrAdmin) {
            return $this->renderHrAdmin();
        }

        // Manager → team dashboard
        if ($role === UserRole::Manager) {
            return $this->renderManager();
        }

        // Finance → finance dashboard
        if ($role === UserRole::Finance) {
            return $this->renderFinance();
        }

        // Director / Executive
        if ($role === UserRole::Director) {
            return $this->renderExecutive();
        }

        // Default: Employee self-service
        return $this->renderEmployee();
    }

    private function renderHrAdmin()
    {
        $today = Carbon::today();
        $month = $today->month;
        $year = $today->year;

        $employeeCount = Employee::where('status', 'active')->count();

        $onboarding = Employee::where('status', 'onboarding')->count();
        $probation = Employee::where('status', 'probation')->count();
        $newEmployees = Employee::whereMonth('joining_date', $month)->whereYear('joining_date', $year)->count();
        $resignedCount = Employee::where('status', 'resigned')->count();

        $recentEmployees = Employee::with(['user', 'department', 'jobTitle'])
            ->whereIn('status', ['active', 'onboarding', 'probation'])
            ->latest()->take(6)->get();

        $candidateCount = 0;

        return view('dashboard', compact(
            'employeeCount',
            'onboarding',
            'probation',
            'newEmployees',
            'resignedCount',
            'recentEmployees',
            'candidateCount',
        ))->layout('layouts.app', ['title' => 'Dashboard']);
    }

    private function renderManager()
    {
        $manager = Auth::user()->employee;
        $today = Carbon::today();

        $teamIds = $manager
            ? Employee::where('manager_id', $manager->id)->pluck('id')
            : collect();

        $teamAttendance = Attendance::with('employee.user')
            ->where('date', $today)
            ->whereIn('employee_id', $teamIds)
            ->get();

        $presentCount = $teamAttendance->whereNotNull('check_in')->count();
        $lateCount = $teamAttendance->where('is_late', true)->count();
        $absentCount = $teamIds->count() - $presentCount;

        $teamAttendanceList = Employee::with(['user', 'department'])
            ->whereIn('id', $teamIds)
            ->get()
            ->map(function ($emp) use ($teamAttendance) {
                $record = $teamAttendance->firstWhere('employee_id', $emp->id);

                return [
                    'name' => $emp->user->name,
                    'department' => $emp->department?->name,
                    'check_in' => $record?->check_in?->format('H:i'),
                    'check_out' => $record?->check_out?->format('H:i'),
                    'status' => $record?->status ?? 'absent',
                    'is_late' => $record?->is_late ?? false,
                ];
            });

        $pendingLeaves = LeaveRequest::with(['employee.user', 'leaveType'])
            ->whereIn('employee_id', $teamIds)
            ->where('status', 'pending')
            ->latest()->take(10)->get();

        $pendingOt = OtRequest::with('employee.user')
            ->whereIn('employee_id', $teamIds)
            ->where('status', 'pending')
            ->latest()->take(10)->get();

        $reviewsPending = 0;
        $reviewsSubmitted = 0;

        return view('livewire.manager-dashboard', compact(
            'teamAttendanceList',
            'presentCount',
            'lateCount',
            'absentCount',
            'pendingLeaves',
            'pendingOt',
            'reviewsPending',
            'reviewsSubmitted',
        ))->layout('layouts.app', ['title' => 'Manager Dashboard']);
    }

    private function renderFinance()
    {
        return app(FinanceDashboard::class)->render();
    }

    private function renderExecutive()
    {
        return app(ExecutiveDashboard::class)->render();
    }

    private function renderEmployee()
    {
        $user = Auth::user();
        $employee = $user->employee;
        $today = Carbon::today();

        // Today's attendance
        $todayAttendance = $employee
            ? Attendance::where('employee_id', $employee->id)->where('date', $today)->first()
            : null;

        // Leave balances (using allocated_days / used_days from schema)
        $leaveBalances = $employee
            ? LeaveBalance::with('leaveType')->where('employee_id', $employee->id)->whereYear('year', now()->year)->get()
            : collect();

        // My pending OT requests
        $myOtRequests = $employee
            ? OtRequest::where('employee_id', $employee->id)->latest()->take(5)->get()
            : collect();

        // Recent payslips
        $myPayslips = $employee
            ? Payslip::where('employee_id', $employee->id)->with('payroll')->latest()->take(5)->get()
            : collect();

        // Pending leave requests (for inbox display)
        $pendingLeaveCount = $employee
            ? LeaveRequest::where('employee_id', $employee->id)->where('status', 'pending')->count()
            : 0;

        return view('livewire.employee-dashboard', compact(
            'todayAttendance',
            'leaveBalances',
            'myOtRequests',
            'myPayslips',
            'pendingLeaveCount',
        ))->layout('layouts.app', ['title' => 'My Dashboard']);
    }
}
