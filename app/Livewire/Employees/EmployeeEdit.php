<?php

namespace App\Livewire\Employees;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Office;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\ProbationExtendedNotification;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class EmployeeEdit extends Component
{
    use WithFileUploads;

    public Employee $employee;

    public string $activeTab = 'General';

    // ── Account ──────────────────────────────────────────────────────────────
    public string $name = '';

    public string $email = '';

    public string $role = '';

    // ── Personal profile ─────────────────────────────────────────────────────
    public string $employee_id = '';

    public string $phone = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $address = '';

    public string $emergency_contact = '';

    public $photo = null;

    // ── Employment ───────────────────────────────────────────────────────────
    public string $office_id = '';

    public string $department_id = '';

    public string $job_title_id = '';

    public string $manager_id = '';

    public string $shift_id = '';

    public string $salary_cycle = 'A';

    public string $joining_date = '';

    public string $status = '';

    public string $employment_type = '';

    // ── Probation ────────────────────────────────────────────────────────────
    public string $probation_end_date = '';

    public string $extend_end_date = '';

    public string $extend_reason = '';

    public function mount(Employee $employee): void
    {
        $this->authorize('update', $employee);
        $this->employee = $employee->load('user');

        // Account
        $this->name = $this->employee->user->name;
        $this->email = $this->employee->user->email;
        $this->role = $this->employee->user->role->value;

        // Personal
        $this->employee_id = $this->employee->employee_id ?? '';
        $this->phone = $this->employee->phone ?? '';
        $this->date_of_birth = $this->employee->date_of_birth?->format('Y-m-d') ?? '';
        $this->gender = $this->employee->gender ?? '';
        $this->address = $this->employee->address ?? '';
        $this->emergency_contact = $this->employee->emergency_contact ?? '';

        // Employment
        $this->office_id = (string) ($this->employee->office_id ?? '');
        $this->department_id = (string) ($this->employee->department_id ?? '');
        $this->job_title_id = (string) ($this->employee->job_title_id ?? '');
        $this->manager_id = (string) ($this->employee->manager_id ?? '');
        $this->shift_id = (string) ($this->employee->shift_id ?? '');
        $this->salary_cycle = $this->employee->salary_cycle ?? 'A';
        $this->joining_date = $this->employee->joining_date->format('Y-m-d');
        $this->probation_end_date = $this->employee->probation_end_date?->format('Y-m-d') ?? '';
        $this->status = $this->employee->status->value;
        $this->employment_type = $this->employee->employment_type->value;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    // ── Save (General + Job + Personal tabs share the same action) ────────────

    public function save(): void
    {
        $this->authorize('update', $this->employee);

        $this->validate([
            // Account
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->employee->user_id)],
            'role' => 'required|string',
            // Personal
            'employee_id' => ['required', 'string', Rule::unique('employees', 'employee_id')->ignore($this->employee->id)],
            'phone' => 'nullable|string|max:30',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|string|in:male,female,other,prefer_not_to_say',
            'address' => 'nullable|string|max:500',
            'emergency_contact' => 'nullable|string|max:255',
            'photo' => 'nullable|image|max:2048',
            // Employment
            'joining_date' => 'required|date',
            'shift_id' => 'nullable|exists:shift_settings,id',
            'salary_cycle' => 'required|in:A,B',
            'status' => 'required|string',
            'employment_type' => 'required|string',
        ]);

        $this->employee->user->update([
            'name' => $this->name,
            'email' => $this->email,
            'role' => UserRole::from($this->role),
        ]);

        $photoPath = $this->photo
            ? $this->photo->store('employee-photos', 'public')
            : $this->employee->photo;

        $this->employee->update([
            'employee_id' => $this->employee_id,
            'phone' => $this->phone ?: null,
            'date_of_birth' => $this->date_of_birth ?: null,
            'gender' => $this->gender ?: null,
            'address' => $this->address ?: null,
            'emergency_contact' => $this->emergency_contact ?: null,
            'photo' => $photoPath,
            'office_id' => $this->office_id ?: null,
            'department_id' => $this->department_id ?: null,
            'job_title_id' => $this->job_title_id ?: null,
            'manager_id' => $this->manager_id ?: null,
            'shift_id' => $this->shift_id ?: null,
            'salary_cycle' => $this->salary_cycle,
            'joining_date' => $this->joining_date,
            'status' => $this->status,
            'employment_type' => $this->employment_type,
        ]);

        \Flux::toast('Employee updated successfully.');
    }

    // ── Probation ─────────────────────────────────────────────────────────────

    public function confirmProbation(): void
    {
        $this->authorize('update', $this->employee);

        $this->employee->update([
            'status' => EmployeeStatus::Active,
            'probation_end_date' => now()->toDateString(),
        ]);

        $this->status = EmployeeStatus::Active->value;
        \Flux::toast('Probation confirmed. Employee is now permanent.');
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

        if ($this->employee->manager) {
            $this->employee->manager->notify(new ProbationExtendedNotification($this->employee));
        }

        $this->reset('extend_end_date', 'extend_reason');
        \Flux::toast('Probation extended and manager notified.');
    }

    public function render()
    {
        $this->employee->load(['salaries.component', 'shift']);

        return view('livewire.employees.employee-edit', [
            'offices' => Office::all(),
            'departments' => Department::all(),
            'jobTitles' => JobTitle::all(),
            'shifts' => ShiftSetting::all(),
            'managers' => User::whereIn('role', [
                UserRole::SuperAdmin, UserRole::HrAdmin,
                UserRole::Director, UserRole::Manager,
            ])->where('id', '!=', $this->employee->user_id)->get(),
            'roles' => UserRole::cases(),
            'statuses' => EmployeeStatus::cases(),
            'employmentTypes' => EmploymentType::cases(),
        ])->layout('layouts.app', ['title' => 'Edit Employee']);
    }
}
