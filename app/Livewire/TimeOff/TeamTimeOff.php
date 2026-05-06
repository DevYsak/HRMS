<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class TeamTimeOff extends Component
{
    use WithPagination;

    public bool $showReviewModal = false;

    public ?int $selectedRequestId = null;

    public string $reviewer_comment = '';

    public array $form = [
        'leave_type_id' => '',
        'start_date' => '',
        'end_date' => '',
        'reason' => '',
        'is_half_day' => false,
    ];

    public function selectRequest(int $id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $req = LeaveRequest::with(['employee.user', 'leaveType'])->findOrFail($id);

        $this->selectedRequestId = $id;
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

        $this->showReviewModal = false;
        $this->selectedRequestId = null;
        $this->reset(['reviewer_comment', 'form']);
        $this->form = ['leave_type_id' => '', 'start_date' => '', 'end_date' => '', 'reason' => '', 'is_half_day' => false];

        \Flux::toast($status === 'approved' ? 'Leave approved successfully.' : 'Leave request rejected.', variant: $status === 'approved' ? 'success' : 'danger');
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $pendingRequests = LeaveRequest::with(['employee.user', 'leaveType'])
            ->where('status', 'pending')
            ->whereHas('employee', fn ($q) => $q->where('manager_id', Auth::id()))
            ->latest()
            ->get();

        $history = LeaveRequest::with(['employee.user', 'leaveType'])
            ->whereIn('status', ['approved', 'rejected'])
            ->whereHas('employee', fn ($q) => $q->where('manager_id', Auth::id()))
            ->latest()
            ->paginate(10);

        $selectedRequest = $this->selectedRequestId
            ? LeaveRequest::with(['employee.user', 'leaveType'])->find($this->selectedRequestId)
            : null;

        return view('livewire.time-off.team-time-off', [
            'pendingRequests' => $pendingRequests,
            'history' => $history,
            'selectedRequest' => $selectedRequest,
        ])->layout('layouts.app', ['title' => 'Team Time Off']);
    }
}
