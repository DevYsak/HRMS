<?php

namespace App\Livewire\TimeOff;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class TeamTimeOff extends Component
{
    use WithPagination;

    public bool $showReviewModal = false;

    public ?int $selectedRequestId = null;

    public ?LeaveRequest $selectedRequest = null;

    // Keep this property in the query string so notifications can open a specific request.
    protected $queryString = ['selectedRequestId'];

    public string $reviewer_comment = '';

    public array $form = [
        'leave_type_id' => '',
        'start_date' => '',
        'end_date' => '',
        'reason' => '',
        'is_half_day' => false,
    ];

    // Filters
    public string $filterFrom = '';

    public string $filterTo = '';

    public string $filterLeaveType = '';

    public string $filterStatus = '';

    public int $perPage = 10;

    public function mount(): void
    {
        $this->filterFrom = now()->startOfMonth()->format('Y-m-d');
        $this->filterTo = now()->endOfMonth()->format('Y-m-d');

        if ($id = request()->query('selectedRequestId')) {
            $this->selectRequest((int) $id);
        }
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->filterFrom = now()->startOfMonth()->format('Y-m-d');
        $this->filterTo = now()->endOfMonth()->format('Y-m-d');
        $this->filterLeaveType = '';
        $this->filterStatus = '';
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function selectRequest(int $id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $req = LeaveRequest::with(['employee.user', 'leaveType'])->findOrFail($id);

        $this->selectedRequestId = $id;
        $this->selectedRequest = $req;
        $this->reviewer_comment = $req->reviewer_comment ?? '';
        $this->form = [
            'leave_type_id' => $req->leave_type_id,
            'start_date' => $req->start_date->format('Y-m-d'),
            'end_date' => $req->end_date->format('Y-m-d'),
            'reason' => $req->reason,
            'is_half_day' => (bool) $req->is_half_day,
        ];
        $this->resetErrorBag();
        $this->showReviewModal = true;
    }

    public function approve(): void
    {
        $this->updateStatus('approved');
    }

    public function reject(): void
    {
        $this->validate([
            'reviewer_comment' => ['required', 'min:5'],
        ], [
            'reviewer_comment.required' => 'Please provide a reason for the rejection.',
            'reviewer_comment.min' => 'Reason must be at least 5 characters.',
        ]);

        $this->updateStatus('rejected');
    }

    public function closeReviewModal(): void
    {
        $this->showReviewModal = false;
        $this->selectedRequestId = null;
        $this->selectedRequest = null;
        $this->resetErrorBag();
        $this->reset(['reviewer_comment', 'form']);
        $this->form = ['leave_type_id' => '', 'start_date' => '', 'end_date' => '', 'reason' => '', 'is_half_day' => false];
    }

    protected function updateStatus(string $status): void
    {
        abort_unless($this->selectedRequestId !== null, 422);
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->validate([
            'form.leave_type_id' => ['required', 'exists:leave_types,id'],
            'form.start_date' => ['required', 'date'],
            'form.end_date' => ['required', 'date', 'after_or_equal:form.start_date'],
        ]);

        $leaveRequest = LeaveRequest::findOrFail($this->selectedRequestId);

        try {
            app(LeaveService::class)->reviewRequest(
                $leaveRequest,
                $this->form,
                $status,
                Auth::id(),
                $this->reviewer_comment ?: null,
            );
        } catch (\DomainException $exception) {
            $this->addError('form.leave_type_id', $exception->getMessage());

            return;
        }

        $this->closeReviewModal();

        \Flux::toast($status === 'approved' ? 'Leave approved successfully.' : 'Leave request rejected.', variant: $status === 'approved' ? 'success' : 'danger');
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $managerId = Auth::id();

        $pendingRequests = LeaveRequest::with(['employee.user', 'employee.department', 'leaveType'])
            ->where('status', 'pending')
            ->whereHas('employee', fn ($q) => $q->where('manager_id', $managerId))
            ->latest()
            ->get();

        $historyQuery = LeaveRequest::with(['employee.user', 'employee.department', 'leaveType'])
            ->whereHas('employee', fn ($q) => $q->where('manager_id', $managerId));

        if ($this->filterFrom) {
            $historyQuery->where('start_date', '>=', $this->filterFrom);
        }

        if ($this->filterTo) {
            $historyQuery->where('start_date', '<=', $this->filterTo);
        }

        if ($this->filterLeaveType) {
            $historyQuery->where('leave_type_id', $this->filterLeaveType);
        }

        if ($this->filterStatus) {
            $historyQuery->where('status', $this->filterStatus);
        } else {
            $historyQuery->whereIn('status', ['approved', 'rejected', 'pending']);
        }

        $history = $historyQuery->latest()->paginate($this->perPage);

        // KPI stats
        $approvedThisMonth = LeaveRequest::whereHas('employee', fn ($q) => $q->where('manager_id', $managerId))
            ->where('status', 'approved')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->count();

        $totalDaysThisMonth = LeaveRequest::whereHas('employee', fn ($q) => $q->where('manager_id', $managerId))
            ->where('status', 'approved')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->sum('days');

        $teamMembersCount = Employee::where('manager_id', $managerId)
            ->where('status', 'active')
            ->count();

        $leaveTypes = LeaveType::orderBy('name')->get();

        return view('livewire.time-off.team-time-off', [
            'pendingRequests' => $pendingRequests,
            'history' => $history,
            'approvedThisMonth' => $approvedThisMonth,
            'totalDaysThisMonth' => $totalDaysThisMonth,
            'teamMembersCount' => $teamMembersCount,
            'leaveTypes' => $leaveTypes,
        ])->layout('layouts.app', ['title' => 'Team Time Off']);
    }
}
