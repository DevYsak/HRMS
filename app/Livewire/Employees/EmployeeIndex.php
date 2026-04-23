<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Models\Office;
use App\Models\JobTitle;
use Livewire\Component;
use Livewire\WithPagination;

class EmployeeIndex extends Component
{
    use WithPagination;

    public $search = '';
    public $office_id = '';
    public $job_title_id = '';
    public $status = '';

    public function mount()
    {
        $this->authorize('viewAny', Employee::class);
    }

    public function updatingSearch()
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

        $employees = Employee::with(['user', 'office', 'department', 'jobTitle', 'manager'])
            ->when(! $user->canManageEmployees(), function ($query) use ($user) {
                // If they are just a manager, only show their direct reports
                $query->where('manager_id', $user->employee?->id);
            })
            ->when($this->search, function ($query) {
                $query->whereHas('user', function ($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('email', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->office_id, function ($query) {
                $query->where('office_id', $this->office_id);
            })
            ->when($this->job_title_id, function ($query) {
                $query->where('job_title_id', $this->job_title_id);
            })
            ->when($this->status, function ($query) {
                $query->where('status', $this->status);
            })
            ->paginate(15);

        return view('livewire.employees.employee-index', [
            'employees' => $employees,
            'offices' => Office::all(),
            'jobTitles' => JobTitle::all(),
        ])->layout('layouts.app', ['title' => 'Manage Employees']);
    }
}
