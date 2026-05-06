<?php

namespace App\Livewire\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Office;
use Livewire\Component;
use Livewire\WithPagination;

class EmployeeIndex extends Component
{
    use WithPagination;

    public $search = '';

    public $office_id = '';

    public $department_id = '';

    public $job_title_id = '';

    public $status = '';

    public function mount()
    {
        $this->authorize('viewAny', Employee::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingOfficeId(): void
    {
        $this->resetPage();
    }

    public function updatingDepartmentId(): void
    {
        $this->resetPage();
    }

    public function updatingJobTitleId(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function deleteEmployee($id)
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('delete', $employee);

        $employee->delete();
        $employee->user->delete();

        \Flux::toast('Employee deleted successfully.');
    }

    public function render()
    {
        $user = auth()->user();

        $employees = Employee::with(['user', 'office', 'department', 'jobTitle', 'manager', 'shift'])
            ->when(! $user->canManageEmployees(), function ($query) use ($user) {
                $query->where('manager_id', $user->employee?->id);
            })
            ->when($this->search, function ($query) {
                $s = '%'.$this->search.'%';
                $query->where(function ($q) use ($s) {
                    $q->where('employee_id', 'like', $s)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $s)->orWhere('email', 'like', $s));
                });
            })
            ->when($this->office_id, fn ($q) => $q->where('office_id', $this->office_id))
            ->when($this->department_id, fn ($q) => $q->where('department_id', $this->department_id))
            ->when($this->job_title_id, fn ($q) => $q->where('job_title_id', $this->job_title_id))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.employees.employee-index', [
            'employees' => $employees,
            'offices' => Office::all(),
            'departments' => Department::orderBy('name')->get(),
            'jobTitles' => JobTitle::all(),
        ])->layout('layouts.app', ['title' => 'Manage Employees']);
    }
}
