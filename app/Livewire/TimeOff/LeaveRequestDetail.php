<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Leave\LeaveAdminActionService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCancellationService;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveRequestAccess;
use App\Services\Leave\LeaveRequestPresenter;
use App\Services\Leave\LeaveRequestRevisionService;
use App\Services\Leave\LeaveRequestTimeline;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveService;
use App\Services\People\Employee360Access;
use App\Services\Security\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One leave request, as HR sees it: summary, date allocation, balance
 * impact, approval path and history, attachments, payroll and attendance
 * impact, ledger references and the audit trail — with the actions the
 * viewer may take (approve / reject through the approval policy, cancel,
 * links to correct, adjust the balance or open Employee 360).
 *
 * Every figure is read from the stored request, its day rows, the balance
 * calculator and the ledger; nothing here changes leave data except the
 * actions, which go through LeaveService / LeaveAdminActionService.
 */
class LeaveRequestDetail extends Component
{
    #[Locked]
    public int $requestId;

    public string $comment = '';

    public string $cancelReason = '';

    /** HR's note when deciding an employee's cancellation request (required to reject). */
    public string $cancellationNote = '';

    /** approve | reject | cancel | propose: the action awaiting confirmation. */
    public ?string $confirming = null;

    /**
     * HR's proposed version of the request while 'propose' is open.
     *
     * @var array{start_date?: string, end_date?: string, is_half_day?: bool, half_day_period?: string, leave_type_id?: int|string, paid_days?: string, allocations?: array<string, array{type: string, session: ?string}>}
     */
    public array $proposal = [];

    /** Why HR proposes the change — shown to the employee. */
    public string $proposalReason = '';

    public function mount(LeaveRequest $leaveRequest): void
    {
        abort_unless(app(LeaveRequestAccess::class)->canView(Auth::user(), $leaveRequest), 403);

        $this->requestId = $leaveRequest->id;
    }

    #[Computed]
    public function request(): LeaveRequest
    {
        return LeaveRequest::with([
            'employee.user', 'employee.department', 'employee.manager',
            'leaveType' => fn ($q) => $q->withTrashed(),
            'reviewer', 'hrReviewer', 'attachments', 'dayAllocations.reasonLeaveType', 'pendingCancellation.requester', 'pendingRevision.proposer',
        ])->findOrFail($this->requestId);
    }

    /** HR may propose a change to an open request (the employee confirms it before any decision). */
    #[Computed]
    public function canPropose(): bool
    {
        return app(LeaveRequestRevisionService::class)->mayPropose($this->request, Auth::user());
    }

    #[Computed]
    public function canWithdrawProposal(): bool
    {
        $pending = $this->request->pendingRevision;

        return $pending !== null && app(LeaveRequestRevisionService::class)->mayWithdraw($pending, Auth::user());
    }

    /** Every proposal made on this request, with the employee's answer. */
    #[Computed]
    public function revisions(): Collection
    {
        return $this->request->revisions()->with(['proposer', 'responder'])->get();
    }

    /** Reasons HR may propose instead: the request's own category. */
    #[Computed]
    public function proposalTypes(): Collection
    {
        $request = $this->request;

        return LeaveType::query()->where('is_active', true)
            ->when($request->main_category, fn ($q) => $q->where('leave_category', $request->main_category))
            ->orderBy('name')->get(['id', 'name']);
    }

    /**
     * The proposal as approval would read it — days, paid and unpaid — and
     * what it changes; or why it cannot be proposed.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function proposalPreview(): ?array
    {
        if ($this->confirming !== 'propose') {
            return null;
        }

        try {
            return app(LeaveRequestRevisionService::class)->preview($this->request, $this->proposalInput());
        } catch (\DomainException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Approve / reject: the approval policy decides who (reporting manager first; never oneself). */
    #[Computed]
    public function canDecide(): bool
    {
        $request = $this->request;

        return LeaveRequestPresenter::isPending($request)
            && $request->status !== 'more_info_requested'
            && ! $this->isOwn()
            && app(ApprovalQueueService::class)->canDecide(Auth::user(), $request);
    }

    #[Computed]
    public function canCancel(): bool
    {
        return in_array($this->request->status, ['pending', 'pending_hr', 'approved'], true) && $this->mayManageApproved();
    }

    #[Computed]
    public function canCorrect(): bool
    {
        return $this->request->status === 'approved' && $this->mayManageApproved() && $this->mayOpenBalances();
    }

    #[Computed]
    public function canViewBalances(): bool
    {
        return $this->mayOpenBalances();
    }

    #[Computed]
    public function canAdjust(): bool
    {
        $user = Auth::user();

        return $this->mayOpenBalances() && ! $this->isOwn()
            && ($user->hasPermission('add_leave_balance') || $user->hasPermission('deduct_leave_balance') || $user->hasPermission('correct_leave_balance'));
    }

    #[Computed]
    public function canView360(): bool
    {
        $employee = $this->request->employee;

        return $employee !== null && app(Employee360Access::class)->allows(Auth::user(), $employee);
    }

    /**
     * The balance the request charges, before and after it — from the
     * calculator, never raw columns. Null when nothing is charged (unpaid).
     *
     * @return array{type: string, available: float, pending: float, paid: float, before: float, after: float, note: string}|null
     */
    #[Computed]
    public function balanceImpact(): ?array
    {
        $request = $this->request;
        $type = LeaveType::withTrashed()->find($request->balanceTypeId());
        $paid = LeaveRequestPresenter::paid($request);

        if (! $type || ! $type->is_paid || LeaveCategory::of($type) === LeaveCategory::UNPAID || ! $request->employee) {
            return null;
        }

        $year = app(LeaveYearResolver::class)->forDate($request->start_date);
        $balance = LeaveBalance::where('employee_id', $request->employee_id)->where('leave_type_id', $type->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere(fn ($w) => $w->whereNull('leave_year_id')->where('year', $year->legacyYear())))
            ->first();

        $summary = $balance ? app(LeaveBalanceCalculator::class)->summary($balance) : ['approved_available' => 0.0, 'pending' => 0.0];
        $available = (float) $summary['approved_available'];

        [$before, $after, $note] = match ($request->status) {
            'approved' => [$available + $paid, $available, 'Deducted on approval.'],
            'pending', 'pending_hr', 'more_info_requested' => [$available, $available - $paid, 'Reserved while pending; deducted only if approved.'],
            default => [$available, $available, 'No balance impact: the request is '.str_replace('_', ' ', $request->status).'.'],
        };

        return [
            'type' => $type->name,
            'available' => $available,
            'pending' => (float) ($summary['pending'] ?? 0),
            'paid' => in_array($request->status, ['approved', 'pending', 'pending_hr', 'more_info_requested'], true) ? $paid : 0.0,
            'before' => round($before, 2),
            'after' => round($after, 2),
            'note' => $note,
        ];
    }

    /**
     * Each date of the range: Combined Leave from its day rows; any other
     * request classified the way attendance and payroll read it.
     *
     * @return Collection<int, array{date: Carbon, day: string, status: string, allocation: string, session: ?string, excluded: bool}>
     */
    #[Computed]
    public function dates(): Collection
    {
        $request = $this->request;

        if ($request->isCombined()) {
            return $request->dayAllocations->map(fn ($row) => [
                'date' => $row->date,
                'day' => $row->date->format('D'),
                'status' => ucfirst(str_replace('_', ' ', $row->day_status)),
                'allocation' => $row->is_excluded ? ($row->exclusion_reason ?: 'Excluded') : (LeaveRequestDay::LABELS[$row->allocation_type] ?? $row->allocation_type),
                'session' => $row->session ? ucwords(str_replace('_', ' ', $row->session)) : null,
                'excluded' => (bool) $row->is_excluded,
            ]);
        }

        if (! $request->employee || $request->start_date->diffInDays($request->end_date) > 366) {
            return collect();
        }

        $labels = [
            WorkingDayResolver::WEEKLY_OFF => 'Weekly off',
            WorkingDayResolver::PUBLIC_HOLIDAY => 'Holiday',
            WorkingDayResolver::MDL_SHUTDOWN => 'Company shutdown',
            WorkingDayResolver::EMPLOYMENT_NOT_STARTED => 'Before joining',
            WorkingDayResolver::EMPLOYMENT_ENDED => 'After leaving',
        ];

        return collect(app(WorkingDayResolver::class)->classifyRange($request->employee, $request->start_date, $request->end_date))
            ->map(function (array $row, string $date) use ($request, $labels) {
                $excluded = isset($labels[$row['state']]);
                $paidType = $request->leaveType?->is_paid ?? true;

                return [
                    'date' => Carbon::parse($date),
                    'day' => Carbon::parse($date)->format('D'),
                    'status' => $excluded ? $labels[$row['state']] : 'Working day',
                    'allocation' => $excluded ? 'Excluded' : (($request->is_half_day ? 'Half Day' : 'Full Day').' – '.($paidType ? 'Paid' : 'Unpaid')),
                    'session' => $request->is_half_day && ! $excluded ? ucwords(str_replace('_', ' ', (string) $request->half_day_period)) : null,
                    'excluded' => $excluded,
                ];
            })->values();
    }

    /** @return Collection<int, LeaveLedgerEntry> */
    #[Computed]
    public function ledgerEntries(): Collection
    {
        return LeaveLedgerEntry::with(['leaveType' => fn ($q) => $q->withTrashed(), 'creator'])
            ->whereIn('source_type', ['leave_request', 'leave_request_return'])
            ->where('source_id', $this->requestId)
            ->orderBy('id')->get();
    }

    /**
     * The request's complete timeline: decisions, proposals and answers,
     * deductions and reversals (with ledger entry ids), payroll and
     * cancellation events — system processes named as such.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function history(): Collection
    {
        return app(LeaveRequestTimeline::class)->for($this->request);
    }

    /** HR decides the employee's request to cancel this approved leave. */
    #[Computed]
    public function canDecideCancellation(): bool
    {
        $pending = $this->request->pendingCancellation;

        return $pending !== null && app(LeaveCancellationService::class)->hrMayDecide($pending, Auth::user());
    }

    public function confirm(string $action): void
    {
        $this->resetErrorBag();
        $this->confirming = in_array($action, ['approve', 'reject', 'cancel', 'approve_cancellation', 'reject_cancellation'], true) ? $action : null;
    }

    /** Open the proposal form, starting from the request as it stands. */
    public function openProposal(): void
    {
        abort_unless($this->canPropose, 403);
        $request = $this->request;

        $this->resetErrorBag();
        $this->proposal = [
            'start_date' => $request->start_date->toDateString(),
            'end_date' => $request->end_date->toDateString(),
            'is_half_day' => (bool) $request->is_half_day,
            'half_day_period' => (string) $request->half_day_period,
            'leave_type_id' => $request->leave_type_id,
            'paid_days' => (string) (LeaveRequestPresenter::paid($request) + 0),
            'allocations' => $request->isCombined()
                ? $request->dayAllocations->where('is_excluded', false)
                    ->mapWithKeys(fn ($row) => [$row->date->toDateString() => ['type' => $row->allocation_type, 'session' => $row->session]])->all()
                : [],
        ];
        $this->proposalReason = '';
        $this->confirming = 'propose';
    }

    /** Send the proposal to the employee; the request waits for their answer. */
    public function submitProposal(): void
    {
        abort_unless($this->canPropose, 403);
        $this->validate([
            'proposal.start_date' => ['required', 'date'],
            'proposal.end_date' => ['required', 'date', 'after_or_equal:proposal.start_date'],
            'proposal.paid_days' => ['nullable', 'numeric', 'min:0'],
            'proposalReason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], ['proposal.start_date' => 'start date', 'proposal.end_date' => 'end date', 'proposal.paid_days' => 'paid days', 'proposalReason' => 'reason']);

        $this->attempt(fn () => app(LeaveRequestRevisionService::class)->propose($this->request, Auth::user(), $this->proposalInput(), $this->proposalReason),
            'Changes proposed. The employee confirms them before anything is approved or deducted.');
    }

    /** Take the unanswered proposal back: the request returns as submitted. */
    public function withdrawProposal(): void
    {
        abort_unless($this->canWithdrawProposal, 403);

        $this->attempt(fn () => app(LeaveRequestRevisionService::class)->withdraw($this->request->pendingRevision, Auth::user()),
            'Proposal withdrawn — the request is back as the employee submitted it.');
    }

    /** Approve the employee's cancellation request: the leave is cancelled and its days returned. */
    public function approveCancellation(): void
    {
        abort_unless($this->canDecideCancellation, 403);
        $pending = $this->request->pendingCancellation;

        $this->attempt(fn () => app(LeaveCancellationService::class)->approve($pending, Auth::user(), trim($this->cancellationNote) ?: null),
            'Cancellation approved — the leave is cancelled and its days returned.');
    }

    /** Reject it: the leave stays approved; the employee is told why. */
    public function rejectCancellation(): void
    {
        abort_unless($this->canDecideCancellation, 403);
        $this->validate(['cancellationNote' => ['required', 'string', 'min:5', 'max:1000']], [], ['cancellationNote' => 'reason']);
        $pending = $this->request->pendingCancellation;

        $this->attempt(fn () => app(LeaveCancellationService::class)->reject($pending, Auth::user(), $this->cancellationNote),
            'Cancellation request rejected — the leave stays approved.');
    }

    public function approve(): void
    {
        abort_unless($this->canDecide, 403);
        $this->decide('approved');
    }

    public function reject(): void
    {
        abort_unless($this->canDecide, 403);
        $this->validate(['comment' => ['required', 'string', 'min:5', 'max:1000']], [], ['comment' => 'reason for rejecting']);
        $this->decide('rejected');
    }

    public function cancel(): void
    {
        abort_unless($this->canCancel, 403);
        $this->validate(['cancelReason' => ['required', 'string', 'min:5', 'max:1000']], [], ['cancelReason' => 'reason']);

        $this->attempt(fn () => app(LeaveAdminActionService::class)->cancelApproved($this->request, Auth::user(), trim($this->cancelReason)), 'Leave request cancelled.');
    }

    public function render()
    {
        return view('livewire.time-off.leave-request-detail', [
            'path' => app(ApprovalQueueService::class)->path($this->request),
            'showInternal' => Auth::user()->hasPermission('view_leave_management'),
        ])->layout('layouts.app', ['title' => LeaveRequestPresenter::reference($this->request)]);
    }

    /** Approve or reject through LeaveService: one transaction with the ledger and the audit event. */
    private function decide(string $status): void
    {
        $request = $this->request;
        $form = [
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'reason' => $request->reason,
            'is_half_day' => (bool) $request->is_half_day,
        ];

        $this->attempt(
            fn () => app(LeaveService::class)->reviewRequest($request, $form, $status, Auth::id(), trim($this->comment) ?: null),
            $status === 'approved' ? 'Leave request approved.' : 'Leave request rejected.',
        );
    }

    /** Runs an action; a refusal shows its message, anything else is logged and shown plainly. */
    private function attempt(callable $action, string $done): void
    {
        try {
            $action();
        } catch (AuthorizationException|\DomainException $e) {
            $this->addError('action', $e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::error('HR leave request action failed.', [
                'leave_request_id' => $this->requestId, 'user_id' => Auth::id(), 'action' => $this->confirming,
                'exception' => $e::class, 'message' => $e->getMessage(),
            ]);
            $this->addError('action', 'That could not be completed. Nothing was changed. Please try again.');

            return;
        }

        $this->reset(['comment', 'cancelReason', 'cancellationNote', 'confirming', 'proposal', 'proposalReason']);
        unset($this->request, $this->canDecide, $this->canCancel, $this->canCorrect, $this->canDecideCancellation, $this->balanceImpact, $this->dates, $this->ledgerEntries, $this->history,
            $this->canPropose, $this->canWithdrawProposal, $this->revisions);
        session()->flash('success', $done);
    }

    /** @return array<string, mixed> the proposal form as the revision service reads it */
    private function proposalInput(): array
    {
        $input = [
            'start_date' => $this->proposal['start_date'] ?? null,
            'end_date' => $this->proposal['end_date'] ?? null,
            'leave_type_id' => $this->proposal['leave_type_id'] ?? null,
        ];

        if ($this->request->isCombined()) {
            return $input + ['allocations' => $this->proposal['allocations'] ?? []];
        }

        return $input + [
            'is_half_day' => (bool) ($this->proposal['is_half_day'] ?? false),
            'half_day_period' => ($this->proposal['half_day_period'] ?? '') ?: null,
            'paid_days' => $this->proposal['paid_days'] ?? null,
        ];
    }

    private function isOwn(): bool
    {
        return $this->request->employee?->user_id !== null && (int) $this->request->employee->user_id === (int) Auth::id();
    }

    private function mayManageApproved(): bool
    {
        $employee = $this->request->employee;

        return $employee !== null && ! $this->isOwn()
            && Auth::user()->hasPermission('manage_approved_leave')
            && app(ScopeResolver::class)->covers(Auth::user(), 'manage_approved_leave', $employee);
    }

    private function mayOpenBalances(): bool
    {
        $employee = $this->request->employee;

        return $employee !== null
            && Auth::user()->hasPermission('view_leave_management')
            && app(ScopeResolver::class)->covers(Auth::user(), 'view_leave_management', $employee);
    }
}
