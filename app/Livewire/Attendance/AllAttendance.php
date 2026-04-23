<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Notifications\RegularisationReviewedNotification;
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
        $this->activeRequest = AttendanceRegularisation::with('employee.user', 'attendance')->findOrFail($id);
        $this->reviewComment = '';
        $this->showReviewModal = true;
    }

    public function approveRegularisation()
    {
        if (!$this->activeRequest) return;

        $this->activeRequest->update([
            'status' => 'approved',
            'reviewer_id' => Auth::id(),
            'reviewer_comment' => $this->reviewComment,
            'reviewed_at' => now(),
        ]);

        $attendance = $this->activeRequest->attendance;
        
        if (!$attendance) {
            $attendance = Attendance::create([
                'employee_id' => $this->activeRequest->employee_id,
                'date' => $this->activeRequest->work_date,
            ]);
        }

        AuditLog::record($attendance, 'regularised', $attendance->toArray(), null);

        $checkIn = Carbon::parse($this->activeRequest->requested_check_in);
        $checkOut = Carbon::parse($this->activeRequest->requested_check_out);
        $grossMinutes = $checkIn->diffInMinutes($checkOut);
        $netMinutes = max(0, $grossMinutes - ($attendance->break_minutes ?? 0));
        
        $attendance->update([
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'total_hours' => round($netMinutes / 60, 2),
            'status' => 'on_time',
        ]);

        $this->activeRequest->employee->user->notify(new RegularisationReviewedNotification($this->activeRequest));

        $this->showReviewModal = false;
        $this->activeRequest = null;
        \Flux::toast('Regularisation request approved.');
    }

    public function rejectRegularisation()
    {
        if (!$this->activeRequest) return;

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
        $query = Attendance::query()->with('employee.user');

        if ($this->search) {
            $query->whereHas('employee.user', function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%');
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
