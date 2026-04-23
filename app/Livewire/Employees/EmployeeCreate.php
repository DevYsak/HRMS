<?php

namespace App\Livewire\Employees;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class EmployeeCreate extends Component
{
    public $name = '';
    public $email = '';
    public $role = 'employee';
    
    public $employee_id = '';
    public $office_id = '';
    public $department_id = '';
    public $job_title_id = '';
    public $manager_id = '';
    public $joining_date = '';
    public $status = 'active';
    public $employment_type = 'full-time';

    public function mount()
    {
        $this->authorize('create', Employee::class);
        $this->joining_date = now()->format('Y-m-d');
        // Generate a random employee ID for convenience
        $this->employee_id = 'EMP-' . rand(10000, 99999);
    }

    public function save()
    {
        $this->authorize('create', Employee::class);
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|string',
            'employee_id' => 'required|string|unique:employees,employee_id',
            'joining_date' => 'required|date',
            'status' => 'required|string',
            'employment_type' => 'required|string',
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make('password'),
            'role' => UserRole::from($this->role),
        ]);

        $user->employee()->create([
            'employee_id' => $this->employee_id,
            'office_id' => $this->office_id ?: null,
            'department_id' => $this->department_id ?: null,
            'job_title_id' => $this->job_title_id ?: null,
            'manager_id' => $this->manager_id ?: null,
            'joining_date' => $this->joining_date,
            'status' => $this->status,
            'employment_type' => $this->employment_type,
        ]);

        session()->flash('status', 'Employee created successfully.');
        
        $this->redirect(route('employees.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.employees.employee-create', [
            'offices' => Office::all(),
            'departments' => Department::all(),
            'jobTitles' => JobTitle::all(),
            'managers' => User::whereIn('role', [UserRole::SuperAdmin, UserRole::HrAdmin, UserRole::Director, UserRole::Manager])->get(),
            'roles' => UserRole::cases(),
            'statuses' => EmployeeStatus::cases(),
            'employmentTypes' => EmploymentType::cases(),
        ])->layout('layouts.app', ['title' => 'Add Employee']);
    }
}
