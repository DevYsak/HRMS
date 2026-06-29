<?php

namespace App\Livewire\Attendance;

use App\Models\AttendanceDailySummary;
use App\Models\Department;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only view of the daily attendance summaries pushed by the external
 * Python attendance engine (attendance_daily_summaries). HRMS never calculates
 * these figures — it displays what the engine synced.
 */
class BiometricSummary extends Component
{
    use WithPagination;

    public string $date = '';

    public string $search = '';

    public string $department = '';

    public string $status = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function previousDay(): void
    {
        $this->date = Carbon::parse($this->date)->subDay()->toDateString();
        $this->resetPage();
    }

    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->toDateString();
        $this->resetPage();
    }

    public function today(): void
    {
        $this->date = now()->toDateString();
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function updatingDepartment(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $query = AttendanceDailySummary::query()
            ->with(['employee.user', 'employee.department', 'employee.shift', 'employee.manager'])
            ->whereHas('employee.user')
            ->where('date', $this->date);

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('employee.user', fn ($q2) => $q2->where('name', 'like', '%'.$search.'%'))
                    ->orWhere('employee_code', 'like', '%'.$search.'%');
            });
        }

        if ($this->department !== '') {
            $query->whereHas('employee.department', fn ($q) => $q->where('name', $this->department));
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        // Aggregate stats for the selected day (unfiltered by search/department).
        $dayRows = AttendanceDailySummary::where('date', $this->date)->get();
        $stats = [
            'total' => $dayRows->count(),
            'present' => $dayRows->whereIn('status', ['present', 'late', 'half_day'])->count(),
            'absent' => $dayRows->where('status', 'absent')->count(),
            'late' => $dayRows->filter(fn ($r) => $r->late_minutes > 0 || $r->status === 'late')->count(),
            'leave' => $dayRows->whereIn('status', ['leave', 'holiday', 'weekly_off'])->count(),
            'ot_hours' => round($dayRows->sum('overtime_minutes') / 60, 1),
        ];

        return view('livewire.attendance.biometric-summary', [
            'summaries' => $query->orderBy('employee_code')->paginate(20),
            'departments' => Department::orderBy('name')->pluck('name'),
            'statuses' => ['present', 'late', 'half_day', 'absent', 'leave', 'holiday', 'weekly_off'],
            'stats' => $stats,
        ])->layout('layouts.app', ['title' => 'Biometric Attendance Summary']);
    }
}
