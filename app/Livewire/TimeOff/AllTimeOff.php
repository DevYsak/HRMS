<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Livewire\Component;
use Livewire\WithPagination;

class AllTimeOff extends Component
{
    use WithPagination;

    public $search = '';
    public $status = '';
    public $leave_type_id = '';

    // Management properties
    public $showManageModal = false;
    public $editingId = null;
    public $form = [
        'status' => '',
        'leave_type_id' => '',
        'start_date' => '',
        'end_date' => '',
        'reason' => '',
        'reviewer_comment' => '',
    ];

    public function manageRequest($id)
    {
        $request = LeaveRequest::findOrFail($id);
        $this->editingId = $id;
        $this->form = [
            'status' => $request->status,
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'reason' => $request->reason,
            'reviewer_comment' => $request->reviewer_comment,
        ];
        $this->showManageModal = true;
    }

    public function saveManage()
    {
        $request = LeaveRequest::findOrFail($this->editingId);
        $oldStatus = $request->status;
        $oldDays = $request->days;
        $oldTypeId = $request->leave_type_id;

        $this->validate([
            'form.status' => 'required|in:pending,approved,rejected,cancelled',
            'form.leave_type_id' => 'required|exists:leave_types,id',
            'form.start_date' => 'required|date',
            'form.end_date' => 'required|date|after_or_equal:form.start_date',
        ]);

        $start = \Illuminate\Support\Carbon::parse($this->form['start_date']);
        $end = \Illuminate\Support\Carbon::parse($this->form['end_date']);
        $newDays = $start->diffInDays($end) + 1;

        // Balance adjustment logic
        $employee = $request->employee;

        // 1. Revert old balance if it was approved
        if ($oldStatus === 'approved') {
            $oldBalance = $employee->leaveBalances()->where('leave_type_id', $oldTypeId)->first();
            if ($oldBalance) {
                $oldBalance->decrement('used_days', $oldDays);
            }
        }

        // 2. Update the request
        $request->update([
            'status' => $this->form['status'],
            'leave_type_id' => $this->form['leave_type_id'],
            'start_date' => $this->form['start_date'],
            'end_date' => $this->form['end_date'],
            'days' => $newDays,
            'reason' => $this->form['reason'],
            'reviewer_comment' => $this->form['reviewer_comment'],
            'reviewer_id' => \Illuminate\Support\Facades\Auth::id(),
        ]);

        // 3. Apply new balance if it is now approved
        if ($this->form['status'] === 'approved') {
            $newBalance = $employee->leaveBalances()->where('leave_type_id', $this->form['leave_type_id'])->first();
            if ($newBalance) {
                $newBalance->increment('used_days', $newDays);
            }
        }

        $this->showManageModal = false;
        \Flux::toast('Leave request updated and balances synced.');
    }

    public function render()
    {
        $requests = LeaveRequest::with(['employee.user', 'leaveType', 'reviewer'])
            ->when($this->search, function ($query) {
                $query->whereHas('employee.user', function ($q) {
                    $q->where('name', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->status, function ($query) {
                $query->where('status', $this->status);
            })
            ->when($this->leave_type_id, function ($query) {
                $query->where('leave_type_id', $this->leave_type_id);
            })
            ->latest()
            ->paginate(15);

        return view('livewire.time-off.all-time-off', [
            'requests' => $requests,
            'leaveTypes' => LeaveType::all(),
        ])->layout('layouts.app', ['title' => 'All Employee Leave']);
    }
}
