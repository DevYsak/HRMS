<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class AllTimeOff extends Component
{
    use WithPagination;

    public $search = '';

    public $status = '';

    public $leave_type_id = '';

    // Management properties
    public bool $showManageModal = false;

    public bool $superAdminLocked = false;

    public string $lockedByName = '';

    public $editingId = null;

    public $form = [
        'status' => '',
        'leave_type_id' => '',
        'start_date' => '',
        'end_date' => '',
        'reason' => '',
        'reviewer_comment' => '',
    ];

    public function manageRequest($id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $request = LeaveRequest::with('reviewer')->findOrFail($id);
        $this->editingId = $id;
        $this->form = [
            'status' => $request->status,
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'reason' => $request->reason,
            'reviewer_comment' => $request->reviewer_comment ?? '',
        ];

        // Lock flag: if leave was approved by Super Admin and current user is HR Admin
        $this->superAdminLocked = false;
        $this->lockedByName = '';
        if (
            $request->status === 'approved'
            && $request->reviewer
            && $request->reviewer->isSuperAdmin()
            && Auth::user()->isHrAdmin()
        ) {
            $this->superAdminLocked = true;
            $this->lockedByName = $request->reviewer->name;
        }

        $this->showManageModal = true;
    }

    public function saveManage(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $request = LeaveRequest::with('reviewer')->findOrFail($this->editingId);

        // HR Admin cannot override a Super Admin approved leave without a comment
        if (
            $this->superAdminLocked
            && in_array($this->form['status'], ['rejected', 'cancelled'])
            && empty(trim($this->form['reviewer_comment'] ?? ''))
        ) {
            $this->addError('form.reviewer_comment', 'A comment is required to override a Super Admin approved leave.');

            return;
        }

        $this->validate([
            'form.status' => 'required|in:pending,approved,rejected,cancelled',
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
        } catch (\DomainException $exception) {
            $this->addError('form.leave_type_id', $exception->getMessage());

            return;
        }

        $this->showManageModal = false;
        $this->superAdminLocked = false;
        \Flux::toast('Leave request updated.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $requests = LeaveRequest::with(['employee.user', 'leaveType', 'reviewer'])
            ->when($this->search, function ($query) {
                $query->whereHas('employee.user', function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%');
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
