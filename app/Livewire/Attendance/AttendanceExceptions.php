<?php

namespace App\Livewire\Attendance;

use App\Models\Employee;
use App\Services\Attendance\CoordinatorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Attendance exceptions for the people the viewer monitors — a coordinator's
 * assigned employees and departments, or HR's reach: absent, late, missing
 * check-out and pending regularisations for a day, each with Remind and
 * Escalate (to the manager or HR). Read-only otherwise: no approvals, no
 * edits to attendance.
 */
class AttendanceExceptions extends Component
{
    public string $date = '';

    public string $tab = 'absent';

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermission('monitor_attendance_exceptions'), 403);
        $this->date = now()->toDateString();
    }

    public function remind(int $employeeId, string $type, string $date): void
    {
        try {
            $sent = app(CoordinatorService::class)->remind(Auth::user(), Employee::with('user')->findOrFail($employeeId), $type, $date);
            \Flux::toast($sent ? 'Reminder sent.' : 'Already reminded today.', variant: $sent ? 'success' : 'warning');
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }
    }

    public function escalate(int $employeeId, string $type, string $date, string $to): void
    {
        try {
            $sent = app(CoordinatorService::class)->escalate(Auth::user(), Employee::with(['user', 'manager'])->findOrFail($employeeId), $type, $date, $to);
            \Flux::toast($sent > 0 ? 'Escalated to '.($to === 'hr' ? 'HR' : 'the manager').'.' : 'Already escalated today.', variant: $sent > 0 ? 'success' : 'warning');
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }
    }

    public function render()
    {
        abort_unless(Auth::user()->hasPermission('monitor_attendance_exceptions'), 403);

        try {
            $day = Carbon::parse($this->date ?: now())->startOfDay()->min(Carbon::today());
        } catch (\Throwable) {
            $day = Carbon::today();
        }

        $service = app(CoordinatorService::class);

        return view('livewire.attendance.attendance-exceptions', [
            'exceptions' => $service->exceptions(Auth::user(), $day),
            'monitoredCount' => count($service->monitoredEmployeeIds(Auth::user())),
            'canRemind' => Auth::user()->hasPermission('remind_employees'),
            'day' => $day,
        ])->layout('layouts.app', ['title' => 'Attendance Exceptions']);
    }
}
