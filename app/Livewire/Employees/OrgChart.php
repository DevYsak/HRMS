<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Attendance\TodayStatusService;
use App\Services\Performance\PerformanceStatusService;
use App\Services\Security\PeopleScope;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The reporting structure, as the viewer is allowed to see it.
 *
 * People with view_employee see the tree of everyone that permission reaches
 * (company, department or team — PeopleScope, the same reach every module
 * uses). Anyone else sees their own place: their manager above, their
 * direct reports below. Clicking a person opens a profile drawer whose
 * sections each follow their own permission.
 */
class OrgChart extends Component
{
    public ?int $profileId = null;

    public function mount()
    {
        $user = auth()->user();

        // Allow users to view their own org chart while keeping policy protections
        if ($user->employee) {
            $this->authorize('view', $user->employee);
        } else {
            $this->authorize('viewAny', Employee::class);
        }
    }

    public function openProfile(int $employeeId): void
    {
        abort_unless(in_array($employeeId, $this->visibleIds(), true), 403);

        $this->profileId = $employeeId;
    }

    public function closeProfile(): void
    {
        $this->profileId = null;
    }

    public function render()
    {
        $user = auth()->user();
        [$topLevel, $groupedByManager] = $this->tree();

        return view('livewire.employees.org-chart', [
            'topLevel' => $topLevel,
            'groupedByManager' => $groupedByManager,
            'scope' => $user->hasPermission('view_employee') ? app(PeopleScope::class)->summary($user, 'view_employee') : null,
            'profile' => $this->profileId ? $this->profile($this->profileId) : null,
        ])->layout('layouts.app', ['title' => 'Organizational Chart']);
    }

    /**
     * @return array{0: Collection<int, Employee>, 1: Collection<int|string, Collection<int, Employee>>}
     */
    private function tree(): array
    {
        $user = auth()->user();
        $employee = $user->employee;
        $with = ['user', 'jobTitle', 'department'];

        if ($user->hasPermission('view_employee')) {
            $employees = Employee::whereIn('id', $this->visibleIds())->with($with)->get();
            $userIds = $employees->pluck('user_id')->filter()->all();

            // A root is anyone whose manager is outside what the viewer sees.
            return [
                $employees->filter(fn (Employee $e) => $e->manager_id === null || ! in_array($e->manager_id, $userIds, true))->values(),
                $employees->whereNotNull('manager_id')->groupBy('manager_id'),
            ];
        }

        if (! $employee) {
            return [collect(), collect()];
        }

        // Own place in the structure: manager above, direct reports below.
        $managerEmployee = $employee->manager_id ? Employee::where('user_id', $employee->manager_id)->with($with)->first() : null;
        $reports = Employee::where('manager_id', $employee->user_id)->with($with)->get();
        $self = $employee->load($with);

        return $managerEmployee
            ? [collect([$managerEmployee]), collect([$managerEmployee->user_id => collect([$self]), $employee->user_id => $reports])]
            : [collect([$self]), collect([$employee->user_id => $reports])];
    }

    /**
     * Everyone this viewer may open in the chart.
     *
     * @return array<int, int>
     */
    private function visibleIds(): array
    {
        $user = auth()->user();
        $employee = $user->employee;

        if ($user->hasPermission('view_employee')) {
            $ids = app(PeopleScope::class)->employeeIds($user, 'view_employee', includeSelf: true);

            return $ids ?? Employee::pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if (! $employee) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', [
            $employee->id,
            $employee->manager_id ? Employee::where('user_id', $employee->manager_id)->value('id') : null,
            ...Employee::where('manager_id', $employee->user_id)->pluck('id')->all(),
        ]))));
    }

    /** @return array<string, mixed> */
    private function profile(int $employeeId): array
    {
        $user = auth()->user();
        $people = app(PeopleScope::class);
        $employee = Employee::with(['user', 'jobTitle', 'department', 'manager', 'shift', 'workMode'])->findOrFail($employeeId);
        $isSelf = (int) $employee->user_id === (int) $user->id;
        $covers = fn (string $permission) => $isSelf || ($user->hasPermission($permission) && $people->covers($user, $permission, $employee));

        $attendance = $covers('view_attendance') ? (app(TodayStatusService::class)->summary([$employee->id])['rows'][$employee->id] ?? null) : null;
        $leave = $covers('approve_leave') || $covers('view_leave_management')
            ? LeaveRequest::with('leaveType')->where('employee_id', $employee->id)->where('status', 'approved')
                ->whereDate('end_date', '>=', today())->orderBy('start_date')->first()
            : null;
        $performance = $covers('review_performance') || $covers('view_performance')
            ? app(PerformanceStatusService::class)->summary([$employee->id])
            : null;

        return [
            'employee' => $employee,
            'reports' => Employee::where('manager_id', $employee->user_id)->count(),
            'attendance' => $attendance,
            'showAttendance' => $covers('view_attendance'),
            'leave' => $leave,
            'showLeave' => $covers('approve_leave') || $covers('view_leave_management'),
            'performance' => $performance,
            'showPerformance' => $performance !== null,
        ];
    }
}
