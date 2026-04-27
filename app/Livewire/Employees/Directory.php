<?php

namespace App\Livewire\Employees;

use App\Models\Department;
use App\Models\Employee;
use Livewire\Component;

class Directory extends Component
{
    public $search = '';

    public $department_id = '';

    public function mount()
    {
        $this->authorize('viewAny', Employee::class);
    }

    public function render()
    {
        $user = auth()->user();

        $base = Employee::query()->where('status', 'active')
            ->when($this->search, function ($query) {
                $query->whereHas('user', function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->department_id, function ($query) {
                $query->where('department_id', $this->department_id);
            });

        // Managers and HR (and roles that can manage employees) see full details
        if ($user->canManageEmployees() || $user->isManager()) {
            $employees = $base->with(['user', 'jobTitle', 'department', 'office', 'shift'])->get();
        } else {
            // Regular employees see only active staff with limited fields
            // include `status` so view enum casting is available, and include email for mailto links
            $employees = $base->select('id', 'user_id', 'job_title_id', 'department_id', 'status')
                ->with([
                    'user' => function ($q) {
                        $q->select('id', 'name', 'email');
                    },
                    'jobTitle' => function ($q) {
                        $q->select('id', 'name');
                    },
                    'department' => function ($q) {
                        $q->select('id', 'name');
                    },
                ])->get();
        }

        return view('livewire.employees.directory', [
            'employees' => $employees,
            'departments' => Department::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Employee Directory']);
    }
}
