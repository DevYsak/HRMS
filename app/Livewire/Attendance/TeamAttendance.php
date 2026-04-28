<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Notifications\RegularisationReviewedNotification;
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
        $this->activeRequest = AttendanceRegularisation::with('employee.user', 'attendance')->findOrFail($id);
        $this->reviewComment = '';
        $this->showReviewModal = true;
    }

    public function approveRegularisation()
    {
        if (! $this->activeRequest) {
            return;
        }

        $this->activeRequest->update([
            'status' => 'approved',
            'reviewer_id' => Auth::id(),
            'reviewer_comment' => $this->reviewComment,
            'reviewed_at' => now(),
        ]);

        $attendance = $this->activeRequest->attendance;

        // Ensure attendance exists (in case it was a completely missed punch)
        if (! $attendance) {
            $attendance = Attendance::create([
                'employee_id' => $this->activeRequest->employee_id,
                'date' => $this->activeRequest->work_date,
            ]);
        }

        // Log original
        AuditLog::record($attendance, 'regularised', $attendance->toArray(), null);

        // Calculate new hours
        $checkIn = Carbon::parse($this->activeRequest->requested_check_in);
        $checkOut = Carbon::parse($this->activeRequest->requested_check_out);
        $grossMinutes = $checkIn->diffInMinutes($checkOut);
        $netMinutes = max(0, $grossMinutes - ($attendance->break_minutes ?? 0));

        $attendance->update([
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'total_hours' => round($netMinutes / 60, 2),
            'status' => 'on_time', // Reset to on_time since it's regularised
        ]);

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

        $this->activeRequest->update([
            'status' => 'rejected',
            'reviewer_id' => Auth::id(),
            'reviewer_comment' => $this->reviewComment,
            'reviewed_at' => now(),
        ]);

        $this->activeRequest->employee->user->notify(new RegularisationReviewedNotification($this->activeRequest));

        $this->showReviewModal = false;
        $this->activeRequest = null;
        \Flux::toast('Regularisation request rejected.');
    }

    public function render()
    {
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
