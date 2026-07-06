<?php

namespace App\Livewire\TimeOff;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class TeamTimeOff extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** Team leave calendar month (Y-m); empty = current month. */
    public string $calendarMonth = '';

    public bool $showReviewModal = false;

    public ?int $selectedRequestId = null;

    public ?LeaveRequest $selectedRequest = null;

    protected $queryString = ['selectedRequestId'];

    public string $reviewer_comment = '';

    // Conversation composer
    public string $panelMessage = '';

    public $panelMessageAttachment = null;

    // HR override fields
    public string $hr_override_status = '';

    public string $hr_remark = '';

    public bool $showHrOverride = false;

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

        $req = LeaveRequest::with(['employee.user', 'leaveType', 'paymentAuditLogs.changedByUser', 'reviewer', 'hrReviewer', 'attachments', 'messages.user'])->findOrFail($id);

        $this->selectedRequestId = $id;
        $this->selectedRequest = $req;
        $this->reviewer_comment = $req->reviewer_comment ?? '';
        $this->hr_override_status = $req->approved_leave_status ?? $req->requested_leave_status ?? ($req->leaveType?->is_paid ? 'paid' : 'unpaid');
        $this->hr_remark = '';
        $this->showHrOverride = false;
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

    /** Ask the employee for more information instead of deciding outright. */
    public function requestMoreInfo(): void
    {
        $this->validate([
            'reviewer_comment' => ['required', 'min:5'],
        ], [
            'reviewer_comment.required' => 'A comment is required to explain what information is needed.',
        ]);

        abort_unless($this->selectedRequestId !== null, 422);
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $leaveRequest = LeaveRequest::findOrFail($this->selectedRequestId);

        try {
            app(LeaveService::class)->requestMoreInfo($leaveRequest, Auth::id(), $this->reviewer_comment);
        } catch (\DomainException $exception) {
            $this->addError('reviewer_comment', $exception->getMessage());

            return;
        }

        $this->closeReviewModal();
        \Flux::toast('More information requested from the employee.', variant: 'warning');
        $this->resetPage();
    }

    /** Post a free-form message (optionally with an attachment) into the leave conversation. */
    public function postPanelMessage(LeaveService $service): void
    {
        abort_unless($this->selectedRequestId !== null, 422);
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->validate([
            'panelMessage' => 'nullable|string|max:2000',
            'panelMessageAttachment' => 'nullable|file|max:500|mimes:pdf,jpg,jpeg,png',
        ]);

        $leaveRequest = LeaveRequest::findOrFail($this->selectedRequestId);

        $path = null;
        $name = null;
        if ($this->panelMessageAttachment) {
            $path = $this->panelMessageAttachment->store('leave-attachments', 'public');
            $name = $this->panelMessageAttachment->getClientOriginalName();
        }

        try {
            $service->postMessage($leaveRequest, Auth::user(), $this->panelMessage ?: null, $path, $name);
        } catch (\DomainException $exception) {
            $this->addError('panelMessage', $exception->getMessage());

            return;
        }

        $this->panelMessage = '';
        $this->panelMessageAttachment = null;
        $this->selectedRequest = LeaveRequest::with([
            'employee.user', 'leaveType', 'paymentAuditLogs.changedByUser',
            'reviewer', 'hrReviewer', 'attachments', 'messages.user',
        ])->findOrFail($this->selectedRequestId);

        \Flux::toast('Message sent to the employee.', variant: 'success');
    }

    public function closeReviewModal(): void
    {
        $this->showReviewModal = false;
        $this->selectedRequestId = null;
        $this->selectedRequest = null;
        $this->panelMessage = '';
        $this->panelMessageAttachment = null;
        $this->hr_override_status = '';
        $this->hr_remark = '';
        $this->showHrOverride = false;
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

        // Validate HR override remark if override is being applied
        if ($this->showHrOverride && $this->hr_remark === '') {
            $this->addError('hr_remark', 'HR remark is required when overriding payment status.');

            return;
        }

        $leaveRequest = LeaveRequest::findOrFail($this->selectedRequestId);

        $approvedLeaveStatus = $this->showHrOverride && $this->hr_override_status
            ? $this->hr_override_status
            : null;

        $hrRemark = ($this->showHrOverride && $this->hr_remark) ? $this->hr_remark : null;

        try {
            app(LeaveService::class)->reviewRequest(
                $leaveRequest,
                $this->form,
                $status,
                Auth::id(),
                $this->reviewer_comment ?: null,
            );

            // If HR override status was set and different from requested, apply override
            if ($approvedLeaveStatus && $leaveRequest->fresh()->status === 'approved') {
                $leaveRequest->refresh();
                if ($approvedLeaveStatus !== ($leaveRequest->requested_leave_status ?? 'paid')) {
                    app(LeaveService::class)->hrOverridePaymentStatus(
                        $leaveRequest,
                        Auth::user(),
                        $approvedLeaveStatus,
                        $hrRemark ?? $this->reviewer_comment ?? 'Override applied during approval.',
                    );
                }
            }
        } catch (\DomainException $exception) {
            $this->addError('form.leave_type_id', $exception->getMessage());

            return;
        }

        $this->closeReviewModal();

        \Flux::toast(
            $status === 'approved' ? 'Leave approved successfully.' : 'Leave request rejected.',
            variant: $status === 'approved' ? 'success' : 'danger'
        );
        $this->resetPage();
    }

    public function prevCalMonth(): void
    {
        $this->calendarMonth = Carbon::createFromFormat('Y-m', $this->calendarMonth ?: now()->format('Y-m'))
            ->subMonth()->format('Y-m');
    }

    public function nextCalMonth(): void
    {
        $this->calendarMonth = Carbon::createFromFormat('Y-m', $this->calendarMonth ?: now()->format('Y-m'))
            ->addMonth()->format('Y-m');
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $managerId = Auth::id();

        $pendingRequests = LeaveRequest::with(['employee.user', 'employee.department', 'leaveType'])
            ->whereIn('status', ['pending', 'pending_hr'])
            ->whereHas('employee', fn ($q) => $q->where('manager_id', $managerId))
            ->latest()
            ->get();

        // Attach available balance to each pending request
        $pendingRequests->each(function (LeaveRequest $req) {
            $req->availableBalance = LeaveBalance::where('employee_id', $req->employee_id)
                ->where('leave_type_id', $req->leave_type_id)
                ->where('year', now()->year)
                ->first();
        });

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
            $historyQuery->whereIn('status', ['approved', 'rejected', 'pending', 'pending_hr']);
        }

        $history = $historyQuery->latest()->paginate($this->perPage);

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

        // ── Team leave calendar (monthly, colour-coded by leave type) ──
        $monthStart = Carbon::createFromFormat('Y-m', $this->calendarMonth ?: now()->format('Y-m'))->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        $teamLeaves = LeaveRequest::with(['employee.user', 'leaveType'])
            ->whereHas('employee', fn ($q) => $q->where('manager_id', $managerId))
            ->where('status', 'approved')
            ->where('start_date', '<=', $gridEnd->toDateString())
            ->where('end_date', '>=', $gridStart->toDateString())
            ->get();

        $calendarDays = [];
        $cursor = $gridStart->copy();
        while ($cursor->lte($gridEnd)) {
            $dayLeaves = $teamLeaves
                ->filter(fn ($r) => $cursor->between($r->start_date->copy()->startOfDay(), $r->end_date->copy()->startOfDay()))
                ->map(fn ($r) => [
                    'name' => $r->employee->user->name ?? '—',
                    'type' => $r->leaveType->name ?? 'Leave',
                    'color' => $r->leaveType->color ?? '#6366f1',
                ])
                ->values();

            $calendarDays[] = [
                'date' => $cursor->copy(),
                'inMonth' => $cursor->month === $monthStart->month,
                'isToday' => $cursor->isToday(),
                'isWeekend' => $cursor->isWeekend(),
                'leaves' => $dayLeaves,
            ];
            $cursor->addDay();
        }

        return view('livewire.time-off.team-time-off', [
            'pendingRequests' => $pendingRequests,
            'history' => $history,
            'approvedThisMonth' => $approvedThisMonth,
            'totalDaysThisMonth' => $totalDaysThisMonth,
            'teamMembersCount' => $teamMembersCount,
            'leaveTypes' => $leaveTypes,
            'calendarDays' => $calendarDays,
            'calendarLabel' => $monthStart->format('F Y'),
        ])->layout('layouts.app', ['title' => 'Team Time Off']);
    }
}
