<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class AllAttendance extends Component
{
    use WithPagination;

    public $search = '';

    public $status = '';

    public $date = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public bool $showReviewModal = false;

    public $activeRequest = null;

    public string $reviewComment = '';

    // ── HR Mark Attendance ───────────────────────────────────────────────────
    public bool $showMarkModal = false;

    public string $markEmployeeId = '';

    public string $markDate = '';

    public string $markCheckIn = '';

    public string $markCheckOut = '';

    public string $markWorkMode = 'office';

    public string $markReason = '';

    public function openMarkModal(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->reset(['markEmployeeId', 'markDate', 'markCheckIn', 'markCheckOut', 'markReason']);
        $this->markWorkMode = 'office';
        $this->markDate = now()->format('Y-m-d');
        $this->showMarkModal = true;
    }

    public function submitMarkAttendance(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->validate([
            'markEmployeeId' => 'required|exists:employees,id',
            'markDate' => 'required|date|before_or_equal:today',
            'markCheckIn' => 'required|date_format:H:i',
            'markCheckOut' => 'required|date_format:H:i|after:markCheckIn',
            'markReason' => 'required|string|min:5',
        ]);

        $employee = Employee::with(['user', 'manager'])->findOrFail($this->markEmployeeId);
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $this->markDate)
            ->first();

        AttendanceRegularisation::create([
            'employee_id' => $employee->id,
            'attendance_id' => $attendance?->id,
            'work_date' => $this->markDate,
            'requested_check_in' => $this->markDate.' '.$this->markCheckIn.':00',
            'requested_check_out' => $this->markDate.' '.$this->markCheckOut.':00',
            'reason' => '[HR — '.Auth::user()->name.'] '.$this->markReason,
            'status' => 'pending',
        ]);

        // Notify manager for approval; fallback to HR team if no manager
        $notification = new AttendanceRegularisationNotification(
            $employee->user->name,
            Carbon::parse($this->markDate)->format('d M Y'),
            'pending',
        );

        if ($employee->manager) {
            $employee->manager->notify($notification);
        } else {
            User::whereIn('role', ['hr_admin', 'super_admin'])
                ->each(fn ($u) => $u->notify($notification));
        }

        $this->showMarkModal = false;
        \Flux::toast("Attendance regularisation submitted for {$employee->user->name}. Pending manager approval.");
    }

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
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('employee.user', function ($q2) use ($search) {
                    $q2->where('name', 'like', '%'.$search.'%');
                })->orWhereHas('employee', function ($q2) use ($search) {
                    $q2->where('employee_id', 'like', '%'.$search.'%');
                });
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
            'allEmployees' => Employee::with('user')->orderBy('id')->get(),
        ])->layout('layouts.app', ['title' => 'Employee Attendance']);
    }
}
