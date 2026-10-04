<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeScorecard;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ManagerDashboard extends Component
{
    /** Employment states counted as the working team. */
    private const WORKING_STATUSES = ['onboarding', 'probation', 'confirmed', 'active', 'on-leave', 'notice_period'];

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
                'rejected',
                Auth::id(),
                $this->rejectComment,
            );
        } catch (\DomainException $exception) {
            // Decided or cancelled while the modal was open: say so, as
            // quickApproveLeave() does, instead of an error page.
            $this->reset('showRejectModal', 'rejectingLeaveId', 'rejectComment');
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->showRejectModal = false;
        $this->rejectingLeaveId = null;
        $this->rejectComment = '';

        \Flux::toast('Leave request rejected.', variant: 'danger');
    }

    public function render()
    {
        $today = Carbon::today();
        $month = $today->month;
        $year = $today->year;

        // My team = my reach, the same population approvals use: the
        // reporting line (direct reports via employees.manager_id = users.id,
        // teams led, departments headed) plus any department/shift scope — so
        // a Department Head scoped to UK Sales sees exactly that team. The old
        // query compared manager_id with an EMPLOYEE id and could show
        // another user's team.
        $reach = Auth::user()->accessibleEmployeeIds();
        $reachIds = collect($reach ?? Employee::whereNotIn('status', ['inactive', 'archived'])->pluck('id')->all())
            ->reject(fn ($id) => (int) $id === (int) Auth::user()->employee?->id)
            ->values();

        // Headcount, attendance, leave-this-week and KPI widgets count the
        // people working now — a leaver or a hire who has not started is not
        // "absent today". Approvals keep the whole reach, so a leaver's open
        // request can still be decided.
        $teamIds = Employee::whereIn('id', $reachIds)
            ->whereIn('status', self::WORKING_STATUSES)
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $today->toDateString()))
            ->pluck('id');

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
            ->whereIn('employee_id', $reachIds)
            ->where('status', 'pending')
            ->latest()
            ->take(10)
            ->get();

        // --- Pending OT Approvals (team only) ---
        $pendingOt = OtRequest::with('employee.user')
            ->whereIn('employee_id', $reachIds)
            ->where('status', 'pending')
            ->latest()
            ->take(10)
            ->get();

        // --- Team OT this month (spec §5.2: hours and amount) ---
        $teamOt = OvertimeRecord::whereIn('employee_id', $teamIds)
            ->whereBetween('work_date', [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()])
            ->get(['ot_hours', 'ot_amount']);
        $teamOtHours = round((float) $teamOt->sum('ot_hours'), 2);
        $teamOtAmount = round((float) $teamOt->sum('ot_amount'), 2);

        // --- Team on leave this week (names and dates only — no reasons) ---
        $weekStart = $today->copy()->startOfWeek();
        $weekEnd = $today->copy()->endOfWeek();
        $onLeaveThisWeek = LeaveRequest::with('employee.user')
            ->whereIn('employee_id', $teamIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $weekEnd)
            ->whereDate('end_date', '>=', $weekStart)
            ->orderBy('start_date')
            ->get()
            ->map(fn (LeaveRequest $leave) => [
                'name' => $leave->employee?->user?->name ?? '—',
                'from' => $leave->start_date->format('d M'),
                'to' => $leave->end_date->format('d M'),
            ]);

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
            'teamOtHours',
            'teamOtAmount',
            'onLeaveThisWeek',
        ))->layout('layouts.app', ['title' => 'Manager Dashboard']);
    }
}
