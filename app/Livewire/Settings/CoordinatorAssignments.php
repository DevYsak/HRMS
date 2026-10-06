<?php

namespace App\Livewire\Settings;

use App\Models\AttendanceSetting;
use App\Models\CoordinatorAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Settings → Coordinators: HR chooses which employees and whole departments
 * each coordinator monitors, the reminder interval and the late threshold
 * coordinator alerts use. Coordinators are people holding Monitor
 * Attendance Exceptions without employee management (HR sees its own reach
 * anyway). Every change is audited.
 */
class CoordinatorAssignments extends Component
{
    public ?int $coordinatorId = null;

    public string $addDepartmentId = '';

    public string $addEmployeeId = '';

    public int $reminderHours = 2;

    public int $lateMinutes = 15;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermission('assign_coordinators'), 403);

        $settings = AttendanceSetting::first();
        $this->reminderHours = (int) ($settings?->coordinator_reminder_hours ?? 2);
        $this->lateMinutes = (int) ($settings?->coordinator_late_minutes ?? 15);
    }

    public function assign(string $kind): void
    {
        abort_unless(Auth::user()->hasPermission('assign_coordinators'), 403);
        abort_unless(in_array($kind, ['department', 'employee'], true), 422);

        $this->validate([
            'coordinatorId' => ['required', 'integer', 'in:'.$this->coordinators()->pluck('id')->implode(',')],
            $kind === 'department' ? 'addDepartmentId' : 'addEmployeeId' => $kind === 'department'
                ? ['required', 'exists:departments,id']
                : ['required', 'exists:employees,id'],
        ]);

        $column = $kind === 'department' ? 'department_id' : 'employee_id';
        $value = (int) ($kind === 'department' ? $this->addDepartmentId : $this->addEmployeeId);

        $assignment = CoordinatorAssignment::firstOrCreate(
            ['coordinator_user_id' => $this->coordinatorId, $column => $value],
            ['created_by' => Auth::id()],
        );

        if ($assignment->wasRecentlyCreated) {
            app(AuditService::class)->event('COORDINATOR_ASSIGNED', AuditService::SETTINGS, $assignment,
                new: ['coordinator_user_id' => $this->coordinatorId, $column => $value], module: 'attendance');
        }

        $this->reset(['addDepartmentId', 'addEmployeeId']);
    }

    public function unassign(int $assignmentId): void
    {
        abort_unless(Auth::user()->hasPermission('assign_coordinators'), 403);

        $assignment = CoordinatorAssignment::findOrFail($assignmentId);
        app(AuditService::class)->event('COORDINATOR_UNASSIGNED', AuditService::SETTINGS, $assignment,
            old: $assignment->only(['coordinator_user_id', 'employee_id', 'department_id']), module: 'attendance');
        $assignment->delete();
    }

    public function saveThresholds(): void
    {
        abort_unless(Auth::user()->hasPermission('assign_coordinators'), 403);

        $this->validate([
            'reminderHours' => ['required', 'integer', 'min:1', 'max:24'],
            'lateMinutes' => ['required', 'integer', 'min:0', 'max:240'],
        ]);

        // Audited by the configuration observer on AttendanceSetting.
        AttendanceSetting::firstOrCreate([])->update([
            'coordinator_reminder_hours' => $this->reminderHours,
            'coordinator_late_minutes' => $this->lateMinutes,
        ]);

        \Flux::toast('Saved.', variant: 'success');
    }

    /** People who can be coordinators: the permission, without employee management. */
    private function coordinators()
    {
        return User::with('assignedRole')->whereNull('deleted_at')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('monitor_attendance_exceptions') && ! $u->canManageEmployees())
            ->values();
    }

    public function render()
    {
        abort_unless(Auth::user()->hasPermission('assign_coordinators'), 403);

        return view('livewire.settings.coordinator-assignments', [
            'coordinators' => $this->coordinators(),
            'assignments' => CoordinatorAssignment::with(['coordinator', 'employee.user', 'department'])
                ->when($this->coordinatorId, fn ($q) => $q->where('coordinator_user_id', $this->coordinatorId))
                ->latest('id')->get(),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'employees' => Employee::with('user')->whereHas('user')->whereNotIn('status', ['inactive', 'archived'])->get()
                ->sortBy(fn ($e) => $e->user?->name)->values(),
        ])->layout('layouts.app', ['title' => 'Coordinators']);
    }
}
