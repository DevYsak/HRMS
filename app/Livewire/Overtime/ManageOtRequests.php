<?php

namespace App\Livewire\Overtime;

use App\Models\OtRequest;
use App\Services\OvertimeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ManageOtRequests extends Component
{
    use WithPagination;

    public string $filterStatus = 'pending';
    public string $filterSearch = '';

    // Review modal
    public bool    $showReviewModal = false;
    public ?int    $reviewingId     = null;
    public string  $reviewAction    = ''; // 'approve' | 'reject'
    public string  $reviewComment   = '';

    public function openReview(int $id, string $action): void
    {
        $this->checkOtPermission();
        $this->reviewingId    = $id;
        $this->reviewAction   = $action;
        $this->reviewComment  = '';
        $this->showReviewModal = true;
    }

    public function submitReview(OvertimeService $service): void
    {
        $this->checkOtPermission();

        $this->validate([
            'reviewComment' => $this->reviewAction === 'reject'
                ? 'required|min:5'
                : 'nullable|max:500',
        ]);

        $request = OtRequest::findOrFail($this->reviewingId);

        if (! $request->isPending()) {
            \Flux::toast('This request has already been reviewed.', variant: 'warning');
            $this->showReviewModal = false;

            return;
        }

        if ($this->reviewAction === 'approve') {
            $service->approve($request, Auth::id(), $this->reviewComment ?: null);
            \Flux::toast('OT request approved. Overtime record created.');
        } else {
            $service->reject($request, Auth::id(), $this->reviewComment);
            \Flux::toast('OT request rejected.', variant: 'warning');
        }

        // Notify employee
        $request->employee->user->notify(
            new \App\Notifications\OtRequestNotification($request->fresh())
        );

        $this->showReviewModal = false;
        $this->resetPage();
    }

    public function checkOtPermission(): void
    {
        if (! Auth::user()->canApproveOt()) {
            abort(403);
        }
    }

    public function render()
    {
        $this->checkOtPermission();

        $query = OtRequest::with(['employee.user', 'employee.department', 'reviewer'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterSearch, function ($q) {
                $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->filterSearch}%"));
            })
            ->latest();

        return view('livewire.overtime.manage-ot-requests', [
            'requests' => $query->paginate(15),
        ])->layout('layouts.app', ['title' => 'Manage OT Requests']);
    }
}
