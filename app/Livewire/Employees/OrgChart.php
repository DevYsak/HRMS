<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use Livewire\Component;

class OrgChart extends Component
{
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

    public function render()
    {
        $user = auth()->user();
        $employee = $user->employee;
        // If no employee record exists for the user, render an empty tree (admins without employee row)
        if (! $employee) {
            return view('livewire.employees.org-chart', [
                'topLevel' => collect(),
                'groupedByManager' => collect(),
            ])->layout('layouts.app', ['title' => 'Organizational Chart']);
        }

        $topLevel = collect();
        $groupedByManager = collect();

        // Super Admins & HR Admins see full company
        if ($user->isSuperAdmin() || $user->isHrAdmin()) {
            $employees = Employee::with(['user', 'jobTitle', 'department'])->get();
            $topLevel = $employees->whereNull('manager_id');
            $groupedByManager = $employees->whereNotNull('manager_id')->groupBy('manager_id');

            // Department heads see their full department
        } elseif ($user->isDepartmentHead()) {
            $employees = Employee::where('department_id', $employee->department_id)
                ->with(['user', 'jobTitle', 'department'])
                ->get();

            $topLevel = $employees->where('user_id', $user->id);
            if ($topLevel->isEmpty()) {
                $topLevel = collect([$employee->load(['user', 'jobTitle', 'department'])]);
            }

            $groupedByManager = $employees->whereNotNull('manager_id')->groupBy('manager_id');

            // Managers & Employees see their own team (manager above, direct reports below)
        } else {
            $managerUser = $employee->manager; // User or null

            $managerEmployee = null;
            if ($managerUser) {
                $managerEmployee = Employee::where('user_id', $managerUser->id)
                    ->with(['user', 'jobTitle', 'department'])
                    ->first();
            }

            $reports = Employee::where('manager_id', $employee->user_id)
                ->with(['user', 'jobTitle', 'department'])
                ->get();

            if ($managerEmployee) {
                $topLevel = collect([$managerEmployee]);
                $groupedByManager = collect([
                    $managerEmployee->user_id => collect([$employee->load(['user', 'jobTitle', 'department'])]),
                    $employee->user_id => $reports,
                ]);
            } else {
                $topLevel = collect([$employee->load(['user', 'jobTitle', 'department'])]);
                $groupedByManager = collect([
                    $employee->user_id => $reports,
                ]);
            }
        }

        return view('livewire.employees.org-chart', [
            'topLevel' => $topLevel,
            'groupedByManager' => $groupedByManager,
        ])->layout('layouts.app', ['title' => 'Organizational Chart']);
    }
}
