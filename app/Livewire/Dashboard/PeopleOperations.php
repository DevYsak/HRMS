<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboards\PeopleOperationsService;
use App\Services\Performance\PerformanceStatusService;
use App\Services\Security\PeopleScope;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * People Operations Today — the HR dashboard's "who needs attention, what is
 * pending, where, and who acts next". One department selector drives every
 * widget in it (attendance, approvals, reviews, department health, data
 * quality): they all read PeopleOperationsService over the same population,
 * so no widget has a scope of its own.
 */
class PeopleOperations extends Component
{
    #[Url(as: 'dept')]
    public string $department = '';

    public function permission(): string
    {
        return Auth::user()->hasPermission('manage_employees') ? 'manage_employees' : 'view_attendance';
    }

    public function updatedDepartment(): void
    {
        $this->department = (string) (app(PeopleScope::class)->sanitiseDepartment(Auth::user(), $this->permission(), $this->department) ?? '');
    }

    public function render()
    {
        $user = Auth::user();
        $people = app(PeopleScope::class);
        $service = app(PeopleOperationsService::class);
        $permission = $this->permission();

        $departmentId = $people->sanitiseDepartment($user, $permission, $this->department);
        $overview = $service->overview($user, $permission, $departmentId);

        return view('livewire.dashboard.people-operations', [
            'departments' => $people->departmentOptions($user, $permission),
            'departmentId' => $departmentId,
            'scope' => $people->summary($user, $permission, $departmentId ? [$departmentId] : null),
            'overview' => $overview,
            'health' => $departmentId ? collect() : $service->departmentHealth($user, $permission),
            'quality' => $service->dataQuality($overview['employee_ids']),
            'performanceByDepartment' => app(PerformanceStatusService::class)->byDepartment($overview['employee_ids']),
        ]);
    }
}
