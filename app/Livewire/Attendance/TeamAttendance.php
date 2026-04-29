<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class TeamAttendance extends Component
{
    use WithPagination;

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

        // Log original
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

        $manager = Auth::user()->employee;
        $teamIds = $manager ? $manager->subordinates->pluck('id')->toArray() : [];

        $currentlyIn = Attendance::whereIn('employee_id', $teamIds)
            ->where('date', Carbon::today())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->with('employee.user')
            ->get();

        $recentLogs = Attendance::whereIn('employee_id', $teamIds)
            ->with(['employee.user', 'regularisation'])
            ->latest('date')
            ->paginate(10);

        $pendingRegularisations = AttendanceRegularisation::whereIn('employee_id', $teamIds)
            ->where('status', 'pending')
            ->with(['employee.user', 'attendance'])
            ->get();

        return view('livewire.attendance.team-attendance', [
            'currentlyIn' => $currentlyIn,
            'recentLogs' => $recentLogs,
            'pendingRegularisations' => $pendingRegularisations,
        ])->layout('layouts.app', ['title' => 'Team Attendance']);
    }
}
