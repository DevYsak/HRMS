<?php

namespace App\Livewire\TimeOff;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class AllTimeOff extends Component
{
    use WithFileUploads;
    use WithPagination;

    // Filters
    public string $search = '';

    public string $status = '';

    public string $leave_type_id = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public int $perPage = 10;

    // Detail side panel
    public bool $showDetailPanel = false;

    public $viewingId = null;

    public ?LeaveRequest $viewingRequest = null;

    public string $panelReviewComment = '';

    // Conversation composer (panel)
    public string $panelMessage = '';

    public $panelMessageAttachment = null;

    // HR override (panel)
    public string $panelHrOverrideStatus = '';

    public string $panelHrRemark = '';

    public bool $panelShowHrOverride = false;

    // Full manage modal
    public bool $showManageModal = false;

    public bool $superAdminLocked = false;

    public string $lockedByName = '';

    public $editingId = null;

    public array $form = [
        'status' => '',
        'leave_type_id' => '',
        'start_date' => '',
        'end_date' => '',
        'reason' => '',
        'reviewer_comment' => '',
        'approved_leave_status' => '',
        'hr_remark' => '',
    ];

    // New leave request
    public bool $showNewModal = false;

    public array $newForm = [
        'employee_id' => '',
        'leave_type_id' => '',
        'start_date' => '',
        'end_date' => '',
        'reason' => '',
        'is_half_day' => false,
    ];

    public function mount(): void
    {
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->endOfMonth()->format('Y-m-d');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingLeaveTypeId(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    // ── Detail Panel ────────────────────────────────────────────────────────

    public function viewRequest(int $id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->viewingId = $id;
        $this->viewingRequest = LeaveRequest::with([
            'employee.user', 'employee.department', 'leaveType',
            'reviewer', 'hrReviewer', 'paymentAuditLogs.changedByUser',
            'attachments', 'messages.user',
        ])->findOrFail($id);

        $this->panelReviewComment = '';
        $this->panelHrOverrideStatus = $this->viewingRequest->approved_leave_status
            ?? $this->viewingRequest->requested_leave_status
            ?? ($this->viewingRequest->leaveType?->is_paid ? 'paid' : 'unpaid');
        $this->panelHrRemark = '';
        $this->panelShowHrOverride = false;
        $this->resetErrorBag();
        $this->showDetailPanel = true;
    }

    public function closeDetailPanel(): void
    {
        $this->showDetailPanel = false;
        $this->viewingId = null;
        $this->viewingRequest = null;
        $this->panelReviewComment = '';
        $this->panelMessage = '';
        $this->panelMessageAttachment = null;
        $this->panelHrOverrideStatus = '';
        $this->panelHrRemark = '';
        $this->panelShowHrOverride = false;
        $this->resetErrorBag();
    }

    public function quickApprove(): void
    {
        abort_unless($this->viewingId !== null, 422);
        abort_unless(Auth::user()->canApproveLeave(), 403);

        if ($this->panelShowHrOverride && trim($this->panelHrRemark) === '') {
            $this->addError('panelHrRemark', 'HR remark is required when overriding payment status.');

            return;
        }

        $request = LeaveRequest::findOrFail($this->viewingId);

        $form = [
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'reason' => $request->reason,
            'is_half_day' => (bool) $request->is_half_day,
        ];

        try {
            app(LeaveService::class)->reviewRequest($request, $form, 'approved', Auth::id(), $this->panelReviewComment ?: null);

            // Apply HR payment override if requested
            if ($this->panelShowHrOverride && $this->panelHrOverrideStatus) {
                $fresh = $request->fresh();
                $currentEffective = $fresh->approved_leave_status ?? $fresh->requested_leave_status ?? 'paid';
                if ($this->panelHrOverrideStatus !== $currentEffective) {
                    app(LeaveService::class)->hrOverridePaymentStatus(
                        $fresh,
                        Auth::user(),
                        $this->panelHrOverrideStatus,
                        $this->panelHrRemark,
                    );
                }
            }
        } catch (\DomainException $exception) {
            $this->addError('panelReviewComment', $exception->getMessage());

            return;
        }

        $this->viewingRequest = LeaveRequest::with([
            'employee.user', 'employee.department', 'leaveType', 'reviewer',
            'hrReviewer', 'paymentAuditLogs.changedByUser', 'attachments', 'messages.user',
        ])->findOrFail($this->viewingId);

        \Flux::toast('Leave approved successfully.', variant: 'success');
    }

    public function quickReject(): void
    {
        $this->validate(
            ['panelReviewComment' => ['required', 'min:5']],
            ['panelReviewComment.required' => 'A comment is required to reject.'],
        );

        abort_unless($this->viewingId !== null, 422);
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $request = LeaveRequest::findOrFail($this->viewingId);

        $form = [
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'reason' => $request->reason,
            'is_half_day' => (bool) $request->is_half_day,
        ];

        app(LeaveService::class)->reviewRequest($request, $form, 'rejected', Auth::id(), $this->panelReviewComment);

        $this->viewingRequest = LeaveRequest::with([
            'employee.user', 'employee.department', 'leaveType', 'reviewer',
            'hrReviewer', 'paymentAuditLogs.changedByUser', 'attachments', 'messages.user',
        ])->findOrFail($this->viewingId);

        \Flux::toast('Leave rejected.', variant: 'danger');
    }

    /** Ask the employee for more information instead of deciding outright. */
    public function requestMoreInfo(): void
    {
        $this->validate(
            ['panelReviewComment' => ['required', 'min:5']],
            ['panelReviewComment.required' => 'A comment is required to explain what information is needed.'],
        );

        abort_unless($this->viewingId !== null, 422);
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $request = LeaveRequest::findOrFail($this->viewingId);

        try {
            app(LeaveService::class)->requestMoreInfo($request, Auth::id(), $this->panelReviewComment);
        } catch (\DomainException $exception) {
            $this->addError('panelReviewComment', $exception->getMessage());

            return;
        }

        $this->viewingRequest = LeaveRequest::with([
            'employee.user', 'employee.department', 'leaveType', 'reviewer',
            'hrReviewer', 'paymentAuditLogs.changedByUser', 'attachments', 'messages.user',
        ])->findOrFail($this->viewingId);

        \Flux::toast('More information requested from the employee.', variant: 'warning');
    }

    /** Post a free-form message (optionally with an attachment) into the leave conversation. */
    public function postPanelMessage(LeaveService $service): void
    {
        abort_unless($this->viewingId !== null, 422);
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->validate([
            'panelMessage' => 'nullable|string|max:2000',
            'panelMessageAttachment' => 'nullable|file|max:500|mimes:pdf,jpg,jpeg,png',
        ]);

        $request = LeaveRequest::findOrFail($this->viewingId);

        $path = null;
        $name = null;
        if ($this->panelMessageAttachment) {
            $path = $this->panelMessageAttachment->store('leave-attachments', 'public');
            $name = $this->panelMessageAttachment->getClientOriginalName();
        }

        try {
            $service->postMessage($request, Auth::user(), $this->panelMessage ?: null, $path, $name);
        } catch (\DomainException $exception) {
            $this->addError('panelMessage', $exception->getMessage());

            return;
        }

        $this->panelMessage = '';
        $this->panelMessageAttachment = null;

        $this->viewingRequest = LeaveRequest::with([
            'employee.user', 'employee.department', 'leaveType', 'reviewer',
            'hrReviewer', 'paymentAuditLogs.changedByUser', 'attachments', 'messages.user',
        ])->findOrFail($this->viewingId);

        \Flux::toast('Message sent to the employee.', variant: 'success');
    }

    /** Apply HR payment status override on an already-open request. */
    public function applyHrOverride(): void
    {
        abort_unless($this->viewingId !== null, 422);
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->validate([
            'panelHrOverrideStatus' => ['required', 'in:paid,unpaid'],
            'panelHrRemark' => ['required', 'min:10'],
        ]);

        $request = LeaveRequest::findOrFail($this->viewingId);

        try {
            app(LeaveService::class)->hrOverridePaymentStatus(
                $request,
                Auth::user(),
                $this->panelHrOverrideStatus,
                $this->panelHrRemark,
            );
        } catch (\DomainException $exception) {
            $this->addError('panelHrRemark', $exception->getMessage());

            return;
        }

        $this->viewingRequest = LeaveRequest::with([
            'employee.user', 'employee.department', 'leaveType', 'reviewer',
            'hrReviewer', 'paymentAuditLogs.changedByUser', 'attachments', 'messages.user',
        ])->findOrFail($this->viewingId);

        $this->panelShowHrOverride = false;
        $this->panelHrRemark = '';
        \Flux::toast('Payment status updated and employee notified.', variant: 'success');
    }

    // ── Full Manage Modal ────────────────────────────────────────────────────

    public function manageRequest(int $id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $request = LeaveRequest::with(['reviewer', 'paymentAuditLogs.changedByUser'])->findOrFail($id);
        $this->editingId = $id;
        $this->form = [
            'status' => $request->status,
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'reason' => $request->reason,
            'reviewer_comment' => $request->reviewer_comment ?? '',
            'approved_leave_status' => $request->approved_leave_status ?? $request->requested_leave_status ?? '',
            'hr_remark' => '',
        ];

        $this->superAdminLocked = false;
        $this->lockedByName = '';

        if ($request->status === 'approved' && $request->reviewer && $request->reviewer->isSuperAdmin() && Auth::user()->isHrAdmin()) {
            $this->superAdminLocked = true;
            $this->lockedByName = $request->reviewer->name;
        }

        $this->showManageModal = true;
    }

    public function closeManageModal(): void
    {
        $this->showManageModal = false;
        $this->editingId = null;
        $this->superAdminLocked = false;
        $this->resetErrorBag();
        $this->reset(['form']);
        $this->form = ['status' => '', 'leave_type_id' => '', 'start_date' => '', 'end_date' => '', 'reason' => '', 'reviewer_comment' => '', 'approved_leave_status' => '', 'hr_remark' => ''];
    }

    public function saveManage(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $request = LeaveRequest::with(['reviewer', 'leaveType'])->findOrFail($this->editingId);

        if ($this->superAdminLocked && \in_array($this->form['status'], ['rejected', 'cancelled'], true) && empty(trim($this->form['reviewer_comment'] ?? ''))) {
            $this->addError('form.reviewer_comment', 'A comment is required to override a Super Admin approved leave.');

            return;
        }

        // Validate HR remark when payment status is being changed
        $currentEffective = $request->approved_leave_status ?? $request->requested_leave_status ?? 'paid';
        $newPaymentStatus = $this->form['approved_leave_status'] ?? '';
        if ($newPaymentStatus && $newPaymentStatus !== $currentEffective && trim($this->form['hr_remark'] ?? '') === '') {
            $this->addError('form.hr_remark', 'HR remark is required when changing the payment status.');

            return;
        }

        $this->validate([
            'form.status' => 'required|in:pending,pending_hr,approved,rejected,cancelled',
            'form.leave_type_id' => 'required|exists:leave_types,id',
            'form.start_date' => 'required|date',
            'form.end_date' => 'required|date|after_or_equal:form.start_date',
        ]);

        try {
            app(LeaveService::class)->reviewRequest(
                $request,
                $this->form,
                $this->form['status'],
                Auth::id(),
                $this->form['reviewer_comment'] ?: null,
            );

            // Apply payment override if applicable
            if ($newPaymentStatus && $newPaymentStatus !== $currentEffective) {
                app(LeaveService::class)->hrOverridePaymentStatus(
                    $request->fresh(),
                    Auth::user(),
                    $newPaymentStatus,
                    $this->form['hr_remark'],
                );
            }
        } catch (\DomainException $exception) {
            $this->addError('form.leave_type_id', $exception->getMessage());

            return;
        }

        $this->closeManageModal();
        \Flux::toast('Leave request updated.');
    }

    // ── New Leave Request ────────────────────────────────────────────────────

    public function openNewModal(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->newForm = ['employee_id' => '', 'leave_type_id' => '', 'start_date' => '', 'end_date' => '', 'reason' => '', 'is_half_day' => false];
        $this->resetErrorBag();
        $this->showNewModal = true;
    }

    public function closeNewModal(): void
    {
        $this->showNewModal = false;
        $this->resetErrorBag();
        $this->reset(['newForm']);
        $this->newForm = ['employee_id' => '', 'leave_type_id' => '', 'start_date' => '', 'end_date' => '', 'reason' => '', 'is_half_day' => false];
    }

    public function submitNewRequest(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->validate([
            'newForm.employee_id' => 'required|exists:employees,id',
            'newForm.leave_type_id' => 'required|exists:leave_types,id',
            'newForm.start_date' => 'required|date',
            'newForm.end_date' => 'required|date|after_or_equal:newForm.start_date',
            'newForm.reason' => 'required|string|min:3',
        ]);

        $employee = Employee::with('user')->findOrFail($this->newForm['employee_id']);
        $leaveType = LeaveType::findOrFail($this->newForm['leave_type_id']);

        try {
            app(LeaveService::class)->submitRequest(
                $employee,
                $leaveType,
                $this->newForm['start_date'],
                $this->newForm['end_date'],
                $this->newForm['reason'],
                (bool) ($this->newForm['is_half_day'] ?? false),
            );
        } catch (\DomainException $exception) {
            $this->addError('newForm.leave_type_id', $exception->getMessage());

            return;
        }

        $this->closeNewModal();
        \Flux::toast("Leave request submitted for {$employee->user->name}.", variant: 'success');
    }

    // ── Render ───────────────────────────────────────────────────────────────

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $query = LeaveRequest::with(['employee.user', 'employee.department', 'leaveType', 'reviewer'])
            ->whereHas('employee.user')
            ->when($this->search, fn ($q) => $q->whereHas('employee.user', fn ($q2) => $q2->where('name', 'like', '%'.$this->search.'%')))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->leave_type_id, fn ($q) => $q->where('leave_type_id', $this->leave_type_id))
            ->when($this->dateFrom, fn ($q) => $q->where('start_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('start_date', '<=', $this->dateTo));

        $requests = $query->latest()->paginate($this->perPage);

        // Attach available balance
        $requests->each(function (LeaveRequest $req) {
            $req->availableBalance = LeaveBalance::where('employee_id', $req->employee_id)
                ->where('leave_type_id', $req->leave_type_id)
                ->where('year', now()->year)
                ->first();
        });

        $kpiBase = LeaveRequest::whereHas('employee.user')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $kpi = [
            'total' => (clone $kpiBase)->count(),
            'pending' => (clone $kpiBase)->whereIn('status', ['pending', 'pending_hr'])->count(),
            'approved' => (clone $kpiBase)->where('status', 'approved')->count(),
            'rejected' => (clone $kpiBase)->where('status', 'rejected')->count(),
        ];

        $allEmployees = Employee::with('user')->whereHas('user')->where('status', 'active')->orderBy('id')->get();

        return view('livewire.time-off.all-time-off', [
            'requests' => $requests,
            'leaveTypes' => LeaveType::orderBy('name')->get(),
            'allEmployees' => $allEmployees,
            'kpi' => $kpi,
        ])->layout('layouts.app', ['title' => 'Employee Leave Master']);
    }
}
