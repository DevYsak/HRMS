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
        $employees = Employee::with(['user', 'jobTitle', 'department'])
            ->when($this->search, function ($query) {
                $query->whereHas('user', function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->department_id, function ($query) {
                $query->where('department_id', $this->department_id);
            })
            ->get();

        return view('livewire.employees.directory', [
            'employees' => $employees,
            'departments' => Department::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Employee Directory']);
    }
}
