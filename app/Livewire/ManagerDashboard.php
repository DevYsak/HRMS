<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\PerformanceReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ManagerDashboard extends Component
{
    public function render()
    {
        $manager   = Auth::user()->employee;
        $today     = Carbon::today();
        $month     = $today->month;
        $year      = $today->year;

        // My direct reports
        $teamIds = $manager
            ? Employee::where('manager_id', $manager->id)->pluck('id')
            : collect();

        // --- Team Attendance Today ---
        $teamAttendance = Attendance::with('employee.user')
            ->where('date', $today)
            ->whereIn('employee_id', $teamIds)
            ->get();

        $presentCount = $teamAttendance->whereNotNull('check_in')->count();
        $lateCount    = $teamAttendance->where('is_late', true)->count();
        $absentCount  = $teamIds->count() - $presentCount;

        // --- Full team attendance list ---
        $teamAttendanceList = Employee::with(['user', 'department'])
            ->whereIn('id', $teamIds)
            ->get()
            ->map(function ($emp) use ($teamAttendance) {
                $record = $teamAttendance->firstWhere('employee_id', $emp->id);
                return [
                    'name'       => $emp->user->name,
                    'department' => $emp->department?->name,
                    'check_in'   => $record?->check_in?->format('H:i'),
                    'check_out'  => $record?->check_out?->format('H:i'),
                    'status'     => $record?->status ?? 'absent',
                    'is_late'    => $record?->is_late ?? false,
                ];
            });

        // --- Pending Leave Approvals (team only) ---
        $pendingLeaves = LeaveRequest::with(['employee.user', 'leaveType'])
            ->whereIn('employee_id', $teamIds)
            ->where('status', 'pending')
            ->latest()
            ->take(10)
            ->get();

        // --- Pending OT Approvals (team only) ---
        $pendingOt = OtRequest::with('employee.user')
            ->whereIn('employee_id', $teamIds)
            ->where('status', 'pending')
            ->latest()
            ->take(10)
            ->get();

        // --- Performance Reviews (team) ---
        $reviewsPending = PerformanceReview::whereIn('employee_id', $teamIds)
            ->whereIn('status', ['draft', 'pending'])
            ->count();

        $reviewsSubmitted = PerformanceReview::whereIn('employee_id', $teamIds)
            ->where('status', 'submitted')
            ->whereYear('submitted_at', $year)
            ->count();

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
}
