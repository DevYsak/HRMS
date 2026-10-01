<?php

namespace App\Livewire\Wfh;

use App\Models\WfhRequest;
use App\Notifications\WfhRequestNotification;
use App\Services\Approvals\ApprovalGuard;
use App\Services\WfhService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class ManageWfhRequests extends Component
{
    use WithPagination;

    public string $filterStatus = '';

    public string $filterSearch = '';

    /** Columns the list may be sorted by (never a raw client string). */
    private const SORTABLE = ['start_date', 'end_date', 'created_at', 'status'];

    public string $sortField = 'start_date';

    public string $sortDirection = 'desc';

    // View modal
    public bool $showViewModal = false;

    // Review modal
    public bool $showReviewModal = false;

    #[Locked]
    public ?int $reviewingId = null;

    public string $reviewAction = '';

    public string $reviewComment = '';

    public ?WfhRequest $selectedRequest = null;

    public function openView(int $id): void
    {
        $this->checkWfhPermission();
        $this->selectedRequest = WfhRequest::with(['employee.user', 'employee.department', 'reviewer'])->findOrFail($id);
        app(ApprovalGuard::class)->assertCanView(Auth::user(), $this->selectedRequest->employee);
        $this->showViewModal = true;
        $this->dispatch('modal-show', name: 'view-wfh-modal');
    }

    public function openReview(int $id, string $action): void
    {
        $this->checkWfhPermission();
        $this->selectedRequest = WfhRequest::with(['employee.user', 'employee.department'])->findOrFail($id);
        app(ApprovalGuard::class)->assertCanDecide(Auth::user(), $this->selectedRequest->employee);
        $this->reviewingId = $id;
        $this->reviewAction = $action;
        $this->reviewComment = $this->selectedRequest->reviewer_comment ?? '';
        $this->showViewModal = false;
        $this->resetErrorBag();
        $this->showReviewModal = true;
        $this->dispatch('modal-close', name: 'view-wfh-modal');
        $this->dispatch('modal-show', name: 'review-wfh-modal');
    }

    public function submitReview(WfhService $service): void
    {
        $this->checkWfhPermission();

        $this->validate([
            'reviewComment' => $this->reviewAction === 'reject'
                ? 'required|min:5'
                : 'nullable|max:500',
        ]);

        $request = WfhRequest::with('employee.user')->findOrFail($this->reviewingId);

        if (! $request->isPending()) {
            \Flux::toast('This request has already been reviewed.', variant: 'warning');
            $this->closeReviewModal();

            return;
        }

        try {
            if ($this->reviewAction === 'approve') {
                $service->approve($request, Auth::id(), $this->reviewComment ?: null);
                \Flux::toast('WFH request approved.', variant: 'success');
            } else {
                $service->reject($request, Auth::id(), $this->reviewComment);
                \Flux::toast('WFH request rejected.', variant: 'warning');
            }
        } catch (\DomainException $exception) {
            $this->addError('reviewComment', $exception->getMessage());

            return;
        }

        $request->employee->user->notify(
            (new WfhRequestNotification($request->fresh()))->forRole('employee')
        );

        $this->closeReviewModal();
        $this->resetPage();
    }

    public function closeReviewModal(): void
    {
        $this->reset(['showReviewModal', 'showViewModal', 'reviewingId', 'reviewAction', 'reviewComment', 'selectedRequest']);
        $this->dispatch('modal-close', name: 'review-wfh-modal');
        $this->dispatch('modal-close', name: 'view-wfh-modal');
    }

    public function clearFilters(): void
    {
        $this->filterSearch = '';
        $this->filterStatus = '';
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = in_array($field, self::SORTABLE, true) ? $field : 'start_date';
            $this->sortDirection = 'desc';
        }
    }

    public function checkWfhPermission(): void
    {
        if (! Auth::user()->canApproveWfh()) {
            abort(403);
        }
    }

    public function render()
    {
        $this->checkWfhPermission();

        // Fail closed: only the approver's reporting line / HR scope.
        $scopeIds = Auth::user()->accessibleEmployeeIds();
        $inReach = fn ($q) => $q->when($scopeIds !== null, fn ($q) => $q->whereIn('employee_id', $scopeIds));

        $query = WfhRequest::with(['employee.user', 'employee.department', 'reviewer'])
            ->tap($inReach)
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterSearch, function ($q) {
                $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->filterSearch}%"));
            })
            ->orderBy(in_array($this->sortField, self::SORTABLE, true) ? $this->sortField : 'start_date', $this->sortDirection === 'asc' ? 'asc' : 'desc');

        $requests = $query->paginate(15);

        return view('livewire.wfh.manage-wfh-requests', [
            'requests' => $requests,
            'pendingCount' => WfhRequest::where('status', 'pending')->tap($inReach)->count(),
        ])->layout('layouts.app', ['title' => 'Manage WFH Requests']);
    }
}
