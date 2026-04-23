<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class TeamTimeOff extends Component
{
    use WithPagination;

    public $showReviewModal = false;

    public $selectedRequest = null;

    public $reviewer_comment = '';

    // Editable fields
    public $form = [
        'leave_type_id' => '',
        'start_date' => '',
        'end_date' => '',
        'reason' => '',
    ];

    public function selectRequest($id)
    {
        $this->selectedRequest = LeaveRequest::with(['employee.user', 'leaveType'])->findOrFail($id);
        $this->reviewer_comment = $this->selectedRequest->reviewer_comment ?? '';
        $this->form = [
            'leave_type_id' => $this->selectedRequest->leave_type_id,
            'start_date' => $this->selectedRequest->start_date->format('Y-m-d'),
            'end_date' => $this->selectedRequest->end_date->format('Y-m-d'),
            'reason' => $this->selectedRequest->reason,
        ];
        $this->showReviewModal = true;
    }

    public function approve()
    {
        $this->updateStatus('approved');
    }

    public function reject()
    {
        $this->validate([
            'reviewer_comment' => 'required|min:5',
        ], [
            'reviewer_comment.required' => 'Please provide a reason for the rejection.',
        ]);

        $this->updateStatus('rejected');
    }

    protected function updateStatus(string $status): void
    {
        if (! $this->selectedRequest) {
            return;
        }

        $oldStatus = $this->selectedRequest->status;
        $oldDays = $this->selectedRequest->days;
        $oldTypeId = $this->selectedRequest->leave_type_id;

        $this->validate([
            'form.leave_type_id' => 'required|exists:leave_types,id',
            'form.start_date' => 'required|date',
            'form.end_date' => 'required|date|after_or_equal:form.start_date',
        ]);

        $start = Carbon::parse($this->form['start_date']);
        $end = Carbon::parse($this->form['end_date']);
        $newDays = $start->diffInDays($end) + 1;

        $employee = $this->selectedRequest->employee;

        // 1. Revert old balance if previously approved
        if ($oldStatus === 'approved') {
            $oldBalance = $employee->leaveBalances()->where('leave_type_id', $oldTypeId)->first();
            $oldBalance?->decrement('used_days', $oldDays);
        }

        // 2. Update request
        $this->selectedRequest->update([
            'status' => $status,
            'leave_type_id' => $this->form['leave_type_id'],
            'start_date' => $this->form['start_date'],
            'end_date' => $this->form['end_date'],
            'days' => $newDays,
            'reason' => $this->form['reason'],
            'reviewer_id' => Auth::id(),
            'reviewer_comment' => $this->reviewer_comment,
        ]);

        // 3. Apply balance if approved
        if ($status === 'approved') {
            $newBalance = $employee->leaveBalances()->where('leave_type_id', $this->form['leave_type_id'])->first();
            $newBalance?->increment('used_days', $newDays);
        }

        $this->showReviewModal = false;
        \Flux::toast('Leave request '.$status.'.');
        $this->resetPage();
    }

    public function render()
    {
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

        return view('livewire.time-off.team-time-off', [
            'pendingRequests' => $pendingRequests,
            'history' => $history,
        ])->layout('layouts.app', ['title' => 'Team Time Off']);
    }
}
