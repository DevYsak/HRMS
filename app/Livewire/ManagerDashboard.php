<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeScorecard;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ManagerDashboard extends Component
{
    public bool $showRejectModal = false;

    public ?int $rejectingLeaveId = null;

    public string $rejectComment = '';

    public function quickApproveLeave(int $leaveId): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $leaveRequest = LeaveRequest::with('employee')->findOrFail($leaveId);

        try {
            app(LeaveService::class)->reviewRequest(
                $leaveRequest,
                [
                    'leave_type_id' => $leaveRequest->leave_type_id,
                    'start_date' => $leaveRequest->start_date->format('Y-m-d'),
                    'end_date' => $leaveRequest->end_date->format('Y-m-d'),
                    'reason' => $leaveRequest->reason,
                    'is_half_day' => (bool) $leaveRequest->is_half_day,
                ],
                'approved',
                Auth::id(),
            );
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Leave approved successfully.');
    }

    public function openRejectModal(int $leaveId): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->rejectingLeaveId = $leaveId;
        $this->rejectComment = '';
        $this->showRejectModal = true;
    }

    public function quickRejectLeave(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);
        abort_unless($this->rejectingLeaveId !== null, 422);

        $this->validate([
            'rejectComment' => ['required', 'min:5'],
        ], [
            'rejectComment.required' => 'Please provide a reason for rejection.',
            'rejectComment.min' => 'Reason must be at least 5 characters.',
        ]);

        $leaveRequest = LeaveRequest::findOrFail($this->rejectingLeaveId);

        app(LeaveService::class)->reviewRequest(
            $leaveRequest,
            [
                'leave_type_id' => $leaveRequest->leave_type_id,
                'start_date' => $leaveRequest->start_date->format('Y-m-d'),
                'end_date' => $leaveRequest->end_date->format('Y-m-d'),
                'reason' => $leaveRequest->reason,
                'is_half_day' => (bool) $leaveRequest->is_half_day,
            ],
            'rejected',
            Auth::id(),
            $this->rejectComment,
        );

        $this->showRejectModal = false;
        $this->rejectingLeaveId = null;
        $this->rejectComment = '';

        \Flux::toast('Leave request rejected.', variant: 'danger');
    }

    public function render()
    {
        $manager = Auth::user()->employee;
        $today = Carbon::today();
        $month = $today->month;
        $year = $today->year;

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
        $lateCount = $teamAttendance->where('is_late', true)->count();
        $absentCount = $teamIds->count() - $presentCount;

        // --- Full team attendance list ---
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

        // --- Team KPI scores (latest performance cycle) ---
        $latestCycle = PerformanceCycle::whereIn('status', ['active', 'completed', 'locked'])
            ->latest('start_date')
            ->first();
        $teamKpis = ($latestCycle && $teamIds->isNotEmpty())
            ? EmployeeScorecard::with('employee.user')
                ->where('performance_cycle_id', $latestCycle->id)
                ->whereIn('employee_id', $teamIds)
                ->orderByDesc('final_score')
                ->get()
            : collect();
        $teamAvgKpi = $teamKpis->isNotEmpty() ? round($teamKpis->avg('final_score'), 1) : null;

        return view('livewire.manager-dashboard', compact(
            'teamAttendanceList',
            'presentCount',
            'lateCount',
            'absentCount',
            'pendingLeaves',
            'pendingOt',
            'reviewsPending',
            'reviewsSubmitted',
            'teamKpis',
            'teamAvgKpi',
            'latestCycle',
        ))->layout('layouts.app', ['title' => 'Manager Dashboard']);
    }
}
