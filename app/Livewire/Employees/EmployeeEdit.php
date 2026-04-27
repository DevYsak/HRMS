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
use App\Notifications\ProbationExtendedNotification;
use Illuminate\Validation\Rule;
use Livewire\Component;

class EmployeeEdit extends Component
{
    public Employee $employee;

    public $activeTab = 'General';

    public $name = '';

    public $email = '';

    public $role = '';

    public $employee_id = '';

    public $office_id = '';

    public $department_id = '';

    public $job_title_id = '';

    public $manager_id = '';

    public $joining_date = '';

    public $status = '';

    public $employment_type = '';

    // Probation fields
    public $probation_end_date = '';

    public $extend_end_date = '';

    public $extend_reason = '';

    public function mount(Employee $employee)
    {
        $this->authorize('update', $employee);
        $this->employee = $employee->load('user');

        $this->name = $this->employee->user->name;
        $this->email = $this->employee->user->email;
        $this->role = $this->employee->user->role->value;

        $this->employee_id = $this->employee->employee_id;
        $this->office_id = $this->employee->office_id;
        $this->department_id = $this->employee->department_id;
        $this->job_title_id = $this->employee->job_title_id;
        $this->manager_id = $this->employee->manager_id;
        $this->joining_date = $this->employee->joining_date->format('Y-m-d');
        $this->probation_end_date = $this->employee->probation_end_date?->format('Y-m-d') ?? '';
        $this->status = $this->employee->status->value;
        $this->employment_type = $this->employee->employment_type->value;
    }

    public function setTab($tab)
    {
        $this->activeTab = $tab;
    }

    public function confirmProbation(): void
    {
        $this->authorize('update', $this->employee);

        $this->employee->update([
            'status' => EmployeeStatus::Active,
            'probation_end_date' => now()->toDateString(),
        ]);

        $this->status = EmployeeStatus::Active->value;

        $this->dispatch('notify', ['message' => 'Probation confirmed. Employee is now permanent.', 'type' => 'success']);
    }

    public function extendProbation(): void
    {
        $this->authorize('update', $this->employee);

        $this->validate([
            'extend_end_date' => 'required|date|after:today',
            'extend_reason' => 'required|string|max:1000',
        ]);

        $this->employee->update([
            'probation_end_date' => $this->extend_end_date,
            'probation_extension_reason' => $this->extend_reason,
        ]);

        // Notify manager in-app
        if ($this->employee->manager) {
            $this->employee->manager->notify(new ProbationExtendedNotification($this->employee));
        }

        $this->reset('extend_end_date', 'extend_reason');

        $this->dispatch('notify', ['message' => 'Probation extended and manager notified.', 'type' => 'success']);
    }

    public function save()
    {
        $this->authorize('update', $this->employee);
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->employee->user_id)],
            'role' => 'required|string',
            'employee_id' => ['required', 'string', Rule::unique('employees', 'employee_id')->ignore($this->employee->id)],
            'joining_date' => 'required|date',
            'status' => 'required|string',
            'employment_type' => 'required|string',
        ]);

        $this->employee->user->update([
            'name' => $this->name,
            'email' => $this->email,
            'role' => UserRole::from($this->role),
        ]);

        $this->employee->update([
            'employee_id' => $this->employee_id,
            'office_id' => $this->office_id ?: null,
            'department_id' => $this->department_id ?: null,
            'job_title_id' => $this->job_title_id ?: null,
            'manager_id' => $this->manager_id ?: null,
            'joining_date' => $this->joining_date,
            'status' => $this->status,
            'employment_type' => $this->employment_type,
        ]);

        session()->flash('status', 'Employee updated successfully.');

        $this->redirect(route('employees.index'), navigate: true);
    }

    public function render()
    {
        $this->employee->load(['salaries.component']);

        return view('livewire.employees.employee-edit', [
            'offices' => Office::all(),
            'departments' => Department::all(),
            'jobTitles' => JobTitle::all(),
            'managers' => User::whereIn('role', [UserRole::SuperAdmin, UserRole::HrAdmin, UserRole::Director, UserRole::Manager])
                ->where('id', '!=', $this->employee->user_id)
                ->get(),
            'roles' => UserRole::cases(),
            'statuses' => EmployeeStatus::cases(),
            'employmentTypes' => EmploymentType::cases(),
        ])->layout('layouts.app', ['title' => 'Edit Employee']);
    }
}
