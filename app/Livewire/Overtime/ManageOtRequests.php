<?php

namespace App\Livewire\Overtime;

use App\Livewire\Concerns\HandlesClaimLock;
use App\Models\AuditLog;
use App\Models\OtRequest;
use App\Notifications\OtRequestNotification;
use App\Services\OvertimeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ManageOtRequests extends Component
{
    use HandlesClaimLock;
    use WithPagination;

    public string $filterStatus = '';

    public string $filterSearch = '';

    public string $sortField = 'work_date';

    public string $sortDirection = 'desc';

    // View modal
    public bool $showViewModal = false;

    // Edit modal
    public bool $showEditModal = false;

    public string $editWorkDate = '';

    public string $editStartTime = '';

    public string $editEndTime = '';

    public string $editReason = '';

    // Review modal
    public bool $showReviewModal = false;

    public ?int $reviewingId = null;

    public string $reviewAction = '';

    public string $reviewComment = '';

    public ?OtRequest $selectedRequest = null;

    /** Status-change history for the OT being viewed (from the audit log). */
    public array $viewHistory = [];

    public function openView(int $id): void
    {
        $this->checkOtPermission();
        $this->selectedRequest = OtRequest::with(['employee.user', 'employee.department', 'attendance', 'reviewer'])->findOrFail($id);
        $this->viewHistory = $this->historyFor($id);
        $this->showViewModal = true;
        $this->dispatch('modal-show', name: 'view-ot-modal');
    }

    /**
     * Build the status-change timeline for an OT request from the audit log
     * (Nexflow sync + each re-decision), newest first.
     *
     * @return array<int, array{action: string, from: ?string, to: ?string, paid: ?bool, at: string}>
     */
    protected function historyFor(int $id): array
    {
        return AuditLog::where('auditable_type', OtRequest::class)
            ->where('auditable_id', $id)
            ->whereIn('action', ['nexflow_ot_synced', 'nexflow_ot_status_changed'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'action' => $a->action,
                'from' => $a->old_values['status'] ?? null,
                'to' => $a->new_values['status'] ?? null,
                'paid' => $a->new_values['already_paid'] ?? null,
                'at' => $a->created_at?->format('d M Y, H:i'),
            ])->all();
    }

    public function openEdit(int $id): void
    {
        $this->checkOtPermission();
        $req = OtRequest::with(['employee.user', 'employee.department'])->findOrFail($id);
        $this->selectedRequest = $req;
        $this->reviewingId = $id;
        $this->editWorkDate = $req->work_date->format('Y-m-d');
        $this->editStartTime = is_string($req->start_time) ? substr($req->start_time, 0, 5) : '';
        $this->editEndTime = is_string($req->end_time) ? substr($req->end_time, 0, 5) : '';
        $this->editReason = $req->reason ?? '';
        $this->resetErrorBag();
        $this->showEditModal = true;
        $this->dispatch('modal-show', name: 'edit-ot-modal');
    }

    public function saveEdit(): void
    {
        $this->checkOtPermission();

        $this->validate([
            'editWorkDate' => ['required', 'date'],
            'editStartTime' => ['required', 'date_format:H:i'],
            'editEndTime' => ['required', 'date_format:H:i'],
            'editReason' => ['required', 'string', 'max:1000'],
        ]);

        OtRequest::findOrFail($this->reviewingId)->update([
            'work_date' => $this->editWorkDate,
            'start_time' => $this->editStartTime,
            'end_time' => $this->editEndTime,
            'reason' => $this->editReason,
        ]);

        $this->showEditModal = false;
        $this->dispatch('modal-close', name: 'edit-ot-modal');
        \Flux::toast('OT request updated.', variant: 'success');
        $this->selectedRequest = OtRequest::with(['employee.user', 'employee.department'])->find($this->reviewingId);
    }

    public function saveEditAndReject(): void
    {
        $this->saveEdit();
        $this->reviewAction = 'reject';
        $this->reviewComment = '';
        $this->showReviewModal = true;
        $this->dispatch('modal-show', name: 'review-ot-modal');
    }

    public function openReview(int $id, string $action): void
    {
        $this->checkOtPermission();
        $this->selectedRequest = OtRequest::with(['employee.user', 'employee.department', 'attendance', 'claimer'])->findOrFail($id);

        if (! $this->claimForReview($this->selectedRequest)) {
            $this->selectedRequest = null;

            return;
        }

        $this->reviewingId = $id;
        $this->reviewAction = $action;
        $this->reviewComment = $this->selectedRequest->reviewer_comment ?? '';
        $this->showViewModal = false;
        $this->resetErrorBag();
        $this->showReviewModal = true;
        $this->dispatch('modal-close', name: 'view-ot-modal');
        $this->dispatch('modal-show', name: 'review-ot-modal');
    }

    public function submitReview(OvertimeService $service): void
    {
        $this->checkOtPermission();

        $this->validate([
            'reviewComment' => $this->reviewAction === 'reject'
                ? 'required|min:5'
                : 'nullable|max:500',
        ]);

        $request = OtRequest::with(['employee.user', 'attendance', 'claimer'])->findOrFail($this->reviewingId);

        if (! $request->isPending()) {
            \Flux::toast('This request has already been reviewed.', variant: 'warning');
            $this->closeReviewModal();

            return;
        }

        if ($this->claimHeldByOther($request)) {
            \Flux::toast('Being handled by '.($request->claimer?->name ?? 'another reviewer').' — no action needed.', variant: 'warning');
            $this->closeReviewModal();

            return;
        }

        try {
            if ($this->reviewAction === 'approve') {
                $service->approve($request, Auth::id(), $this->reviewComment ?: null);
                \Flux::toast('OT request approved. Overtime record created.', variant: 'success');
            } else {
                $service->reject($request, Auth::id(), $this->reviewComment);
                \Flux::toast('OT request rejected.', variant: 'warning');
            }
        } catch (\DomainException $exception) {
            $this->addError('reviewComment', $exception->getMessage());

            return;
        }

        $request->employee->user->notify(
            (new OtRequestNotification($request->fresh()))->forRole('employee')
        );

        $this->releaseClaim($request);
        $this->closeReviewModal();
        $this->resetPage();
    }

    public function closeReviewModal(): void
    {
        // Backing out without deciding frees the claim for the other reviewers.
        if ($this->reviewingId) {
            $req = OtRequest::find($this->reviewingId);
            if ($req && (int) $req->claimed_by === Auth::id()) {
                $this->releaseClaim($req);
            }
        }

        $this->reset(['showReviewModal', 'showEditModal', 'reviewingId', 'reviewAction', 'reviewComment', 'selectedRequest', 'editWorkDate', 'editStartTime', 'editEndTime', 'editReason']);
        $this->dispatch('modal-close', name: 'review-ot-modal');
        $this->dispatch('modal-close', name: 'edit-ot-modal');
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
            $this->sortField = $field;
            $this->sortDirection = 'desc';
        }
    }

    public function checkOtPermission(): void
    {
        if (! Auth::user()->canApproveOt()) {
            abort(403);
        }
    }

    /**
     * Pull overtime from Nexflow for this month: import approved OT into payroll
     * and record rejected OT for visibility. Also runs automatically every 10
     * minutes via hrms:sync-nexflow-ot-details.
     */
    public function syncFromNexflow(OvertimeService $service): void
    {
        $this->checkOtPermission();

        $result = $service->syncNexflowOtDetails(
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
        );

        \Flux::toast(
            "Nexflow sync — {$result['imported']} imported, {$result['updated']} status change(s), {$result['rejected']} rejected recorded"
            .($result['skipped'] ? ", {$result['skipped']} skipped." : '.')
        );

        $this->resetPage();
    }

    public function render()
    {
        $this->checkOtPermission();

        $query = OtRequest::with(['employee.user', 'employee.department', 'reviewer'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterSearch, function ($q) {
                $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->filterSearch}%"));
            })
            ->orderBy($this->sortField, $this->sortDirection);

        $requests = $query->paginate(15);

        // Overall status mix (unfiltered) for the summary strip.
        $byStatus = OtRequest::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('livewire.overtime.manage-ot-requests', [
            'requests' => $requests,
            'pendingCount' => (int) ($byStatus['pending'] ?? 0),
            'approvedCount' => (int) ($byStatus['approved'] ?? 0),
            'rejectedCount' => (int) ($byStatus['rejected'] ?? 0),
            'nexflowCount' => OtRequest::where('source', 'nexflow')->count(),
        ])->layout('layouts.app', ['title' => 'Manage OT Requests']);
    }
}
