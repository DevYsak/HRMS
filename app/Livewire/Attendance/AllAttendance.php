<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\AttendanceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class AllAttendance extends Component
{
    use WithPagination;

    public $search = '';

    public $status = '';

    public $date = '';

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public $showReviewModal = false;

    public $activeRequest = null;

    public $reviewComment = '';

    public function openReviewModal(int $id)
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->activeRequest = AttendanceRegularisation::with('employee.user', 'attendance')->findOrFail($id);
        $this->reviewComment = '';
        $this->showReviewModal = true;
    }

    public function approveRegularisation()
    {
        if (! $this->activeRequest) {
            return;
        }

        $attendance = app(AttendanceService::class)->approveRegularisation(
            $this->activeRequest,
            Auth::id(),
            $this->reviewComment ?: null,
        );

        AuditLog::record($attendance, 'regularised', $attendance->toArray(), null);

        $this->activeRequest->employee->user->notify(new RegularisationReviewedNotification($this->activeRequest));

        $this->showReviewModal = false;
        $this->activeRequest = null;
        \Flux::toast('Regularisation request approved.');
    }

    public function rejectRegularisation()
    {
        if (! $this->activeRequest) {
            return;
        }

        $this->validate(['reviewComment' => 'required|string|min:5']);

        app(AttendanceService::class)->rejectRegularisation($this->activeRequest, Auth::id(), $this->reviewComment);

        $this->activeRequest->employee->user->notify(new RegularisationReviewedNotification($this->activeRequest));

        $this->showReviewModal = false;
        $this->activeRequest = null;
        \Flux::toast('Regularisation request rejected.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $query = Attendance::query()->with('employee.user');

        if ($this->search) {
            $query->whereHas('employee.user', function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->date) {
            $query->where('date', $this->date);
        }

        $pendingRegularisations = AttendanceRegularisation::where('status', 'pending')
            ->with(['employee.user', 'attendance'])
            ->get();

        return view('livewire.attendance.all-attendance', [
            'attendances' => $query->latest('date')->paginate(15),
            'pendingRegularisations' => $pendingRegularisations,
        ])->layout('layouts.app', ['title' => 'Employee Attendance']);
    }
}
