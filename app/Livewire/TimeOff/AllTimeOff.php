<?php

namespace App\Livewire\TimeOff;

use App\Livewire\Concerns\HandlesClaimLock;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveService;
use App\Services\Security\PeopleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AllTimeOff extends Component
{
    use HandlesClaimLock;
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

    #[Locked]
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

    #[Locked]
    public $editingId = null;

    public array $form = [
        'status' => '',
        'leave_type_id' => '',
        'start_date' => '',
        'end_date' => '',
        'reason' => '',
        'is_half_day' => false,
        'half_day_period' => '',
        'reviewer_comment' => '',
        'approved_leave_status' => '',
        'hr_remark' => '',
    ];

    /** The request's paid / unpaid status when the editor opened (a change needs an HR remark). */
    #[Locked]
    public string $managePaymentWas = '';

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

        $this->viewingRequest = LeaveRequest::with([
            'employee.user', 'employee.department', 'leaveType',
            'reviewer', 'hrReviewer', 'paymentAuditLogs.changedByUser',
            'attachments', 'messages.user', 'claimer',
        ])->findOrFail($id);

        app(ApprovalGuard::class)->assertCanView(Auth::user(), $this->viewingRequest->employee, 'approve_leave');

        // Claim-lock: another in-scope reviewer is already handling this request.
        if (! $this->claimForReview($this->viewingRequest)) {
            $this->viewingRequest = null;

            return;
        }

        $this->viewingId = $id;
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
        // Closing without deciding frees the claim for the other reviewers.
        if ($this->viewingId) {
            $req = LeaveRequest::find($this->viewingId);
            if ($req && (int) $req->claimed_by === Auth::id() && $req->status === 'pending') {
                $this->releaseClaim($req);
            }
        }

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

        $this->releaseClaim($request);
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

        $this->releaseClaim($request);
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
            'panelMessageAttachment' => 'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png,webp',
        ]);

        $request = LeaveRequest::findOrFail($this->viewingId);
        app(ApprovalGuard::class)->assertCanView(Auth::user(), $request->employee, 'approve_leave');

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

        $request = LeaveRequest::with(['employee', 'reviewer', 'paymentAuditLogs.changedByUser'])->findOrFail($id);
        app(ApprovalGuard::class)->assertCanView(Auth::user(), $request->employee, 'approve_leave');

        $this->editingId = $id;
        $this->form = [
            'status' => $request->status,
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'reason' => $request->reason,
            'is_half_day' => (bool) $request->is_half_day,
            'half_day_period' => $request->half_day_period ?? '',
            'reviewer_comment' => $request->reviewer_comment ?? '',
            'approved_leave_status' => $request->approved_leave_status ?? $request->requested_leave_status ?? '',
            'hr_remark' => '',
        ];
        $this->managePaymentWas = (string) $this->form['approved_leave_status'];

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
        $this->form = ['status' => '', 'leave_type_id' => '', 'start_date' => '', 'end_date' => '', 'reason' => '', 'is_half_day' => false, 'half_day_period' => '', 'reviewer_comment' => '', 'approved_leave_status' => '', 'hr_remark' => ''];
        $this->managePaymentWas = '';
    }

    /**
     * Whether the editor may change paid / unpaid: the same rule as
     * LeaveService::hrOverridePaymentStatus (HR Admin or Super Admin, on a
     * leave type that allows an HR override).
     */
    public function canChangePayment(): bool
    {
        $role = Auth::user()?->role;

        return in_array($role?->value ?? $role, ['hr_admin', 'super_admin'], true)
            && (bool) LeaveType::find($this->form['leave_type_id'] ?? null)?->allow_hr_override;
    }

    /** A half-day leave is one day: the end date follows the start date. */
    public function updatedForm(mixed $value, string $key): void
    {
        if (in_array($key, ['is_half_day', 'start_date'], true) && $this->form['is_half_day']) {
            $this->form['end_date'] = $this->form['start_date'];
        }
        if ($key === 'is_half_day' && ! $this->form['is_half_day']) {
            $this->form['half_day_period'] = '';
        }
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
        if ($newPaymentStatus && $newPaymentStatus !== $currentEffective && ! $this->canChangePayment()) {
            $this->addError('form.approved_leave_status', 'Only HR Admin or Super Admin can change paid / unpaid, and only for a leave type that allows it.');

            return;
        }
        if ($newPaymentStatus && $newPaymentStatus !== $currentEffective && trim($this->form['hr_remark'] ?? '') === '') {
            $this->addError('form.hr_remark', 'HR remark is required when changing the payment status.');

            return;
        }

        $this->validate([
            'form.status' => 'required|in:pending,pending_hr,approved,rejected,cancelled',
            'form.leave_type_id' => 'required|exists:leave_types,id',
            'form.start_date' => 'required|date',
            'form.end_date' => ['required', 'date', 'after_or_equal:form.start_date', ...($this->form['is_half_day'] ? ['same:form.start_date'] : [])],
            'form.is_half_day' => 'boolean',
            'form.half_day_period' => 'required_if:form.is_half_day,true|nullable|in:first_half,second_half',
            'form.approved_leave_status' => 'nullable|in:paid,unpaid',
        ], [
            'form.end_date.same' => 'A half-day leave is a single day.',
            'form.half_day_period.required_if' => 'Choose the first or second half.',
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
        // Filing leave for someone else is "apply on behalf", its own permission.
        abort_unless(Auth::user()->canApproveLeave() && Auth::user()->hasPermission('apply_leave_on_behalf'), 403);

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
        abort_unless(Auth::user()->canApproveLeave() && Auth::user()->hasPermission('apply_leave_on_behalf'), 403);

        $this->validate([
            'newForm.employee_id' => 'required|exists:employees,id',
            'newForm.leave_type_id' => 'required|exists:leave_types,id',
            'newForm.start_date' => 'required|date',
            'newForm.end_date' => 'required|date|after_or_equal:newForm.start_date',
            'newForm.reason' => 'required|string|min:3',
        ]);

        $employee = Employee::with('user')->findOrFail($this->newForm['employee_id']);
        abort_unless(Auth::user()->coversEmployee($employee, 'approve_leave'), 403);
        $leaveType = LeaveType::findOrFail($this->newForm['leave_type_id']);

        // Through applyOnBehalf: it records who filed it (applied_by) and
        // audits LEAVE_APPLIED_ON_BEHALF; the bare submitRequest did neither.
        try {
            app(LeaveService::class)->applyOnBehalf(
                Auth::user(),
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

    /**
     * The list, exactly as filtered on screen, over the approve_leave reach —
     * shared by the table and the export so neither can show more.
     *
     * @return Builder<LeaveRequest>
     */
    private function filteredQuery(): Builder
    {
        // null = company-wide; otherwise only the approver's reporting line / scope.
        $scopeIds = Auth::user()->accessibleEmployeeIds('approve_leave');

        return LeaveRequest::with(['employee.user', 'employee.department', 'leaveType', 'reviewer'])
            ->withCount('messages')
            ->whereHas('employee.user')
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('employee_id', $scopeIds))
            ->when($this->search, fn ($q) => $q->whereHas('employee.user', fn ($q2) => $q2->where('name', 'like', '%'.$this->search.'%')))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->leave_type_id, fn ($q) => $q->where('leave_type_id', $this->leave_type_id))
            ->when($this->dateFrom, fn ($q) => $q->where('start_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('start_date', '<=', $this->dateTo));
    }

    /** CSV of the filtered list — only the people this approver reaches. */
    public function export(): StreamedResponse
    {
        abort_unless(Auth::user()->canApproveLeave() && Auth::user()->hasPermission('export_leave'), 403);

        $rows = $this->filteredQuery()->latest()->get();

        AuditLog::record(Auth::user(), 'leave.exported', null, ['rows' => $rows->count(), 'filters' => array_filter([
            'status' => $this->status, 'leave_type_id' => $this->leave_type_id, 'from' => $this->dateFrom, 'to' => $this->dateTo, 'search' => $this->search,
        ])], context: ['module' => 'leave', 'event' => 'leave.exported']);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee', 'Employee ID', 'Department', 'Leave type', 'From', 'To', 'Days', 'Status', 'Reviewer', 'Submitted']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->employee?->user?->name, $r->employee?->employee_id, $r->employee?->department?->name, $r->leaveType?->name,
                    $r->start_date?->toDateString(), $r->end_date?->toDateString(), $r->days, $r->status, $r->reviewer?->name, $r->created_at?->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, 'leave-requests-'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        // null = company-wide; otherwise only the approver's reporting line / scope.
        $scopeIds = Auth::user()->accessibleEmployeeIds('approve_leave');

        $requests = $this->filteredQuery()->latest()->paginate($this->perPage);

        // Attach available balance
        // The leave year runs 1 July to 30 June, so the calendar year and the
        // leave year's integer disagree from January to June — an approver was
        // shown no balance at all for six months of every year.
        $leaveYear = app(LeaveYearResolver::class)->legacyYearFor();

        $requests->each(function (LeaveRequest $req) use ($leaveYear) {
            $req->availableBalance = LeaveBalance::where('employee_id', $req->employee_id)
                ->where('leave_type_id', $req->leave_type_id)
                ->where('year', $leaveYear)
                ->first();
        });

        $kpiBase = LeaveRequest::whereHas('employee.user')
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('employee_id', $scopeIds))
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $kpi = [
            'total' => (clone $kpiBase)->count(),
            'pending' => (clone $kpiBase)->whereIn('status', ['pending', 'pending_hr'])->count(),
            'approved' => (clone $kpiBase)->where('status', 'approved')->count(),
            'rejected' => (clone $kpiBase)->where('status', 'rejected')->count(),
        ];

        $allEmployees = Employee::with('user')->whereHas('user')->where('status', 'active')
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('id', $scopeIds))
            ->orderBy('id')->get();

        return view('livewire.time-off.all-time-off', [
            'scope' => app(PeopleScope::class)->summary(Auth::user(), 'approve_leave'),
            'canExport' => Auth::user()->hasPermission('export_leave'),
            'requests' => $requests,
            'leaveTypes' => LeaveType::orderBy('name')->get(),
            'allEmployees' => $allEmployees,
            'kpi' => $kpi,
        ])->layout('layouts.app', ['title' => 'Employee Leave Master']);
    }
}
