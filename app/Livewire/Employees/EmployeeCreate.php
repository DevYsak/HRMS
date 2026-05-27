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
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * FIX 1 + FIX 3 — Spec §3.1 Employee Management
 * Adds: phone, dob, gender, address, emergency_contact, photo (FIX 1)
 * Adds: shift_id, salary_cycle, probation_end_date (FIX 3)
 */
class EmployeeCreate extends Component
{
    use WithFileUploads;

    // ── Account ──────────────────────────────────────────
    public string $name = '';

    public string $email = '';

    public string $role = 'employee';

    // ── Personal profile (§3.1) ──────────────────────────
    public string $employee_id = '';

    public string $phone = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $address = '';

    public string $emergency_contact = '';

    public $photo = null;

    // ── Employment record (§3.1) ─────────────────────────
    public string $office_id = '';

    public string $department_id = '';

    public string $job_title_id = '';

    public string $manager_id = '';

    public string $shift_id = '';

    public string $salary_cycle = 'A';

    public string $joining_date = '';

    public string $probation_end_date = '';

    public string $status = 'active';

    public string $employment_type = 'full-time';

    public function mount(): void
    {
        $this->authorize('create', Employee::class);
        $this->joining_date = now()->format('Y-m-d');
        $this->probation_end_date = now()->addMonths(3)->format('Y-m-d');

        $lastId = Employee::max('id') ?? 0;
        $this->employee_id = 'CNX-'.str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);
    }

    // Leave allocations keyed by leave_type_id (editable during creation)
    public array $leave_allocations = [];

    public function save(): void
    {
        $this->authorize('create', Employee::class);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', 'string'],
            'employee_id' => ['required', 'string', 'unique:employees,employee_id'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'in:male,female,other,prefer_not_to_say'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:2048'],
            'joining_date' => ['required', 'date'],
            'probation_end_date' => ['nullable', 'date', 'after:joining_date'],
            'shift_id' => ['nullable', 'exists:shift_settings,id'],
            'salary_cycle' => ['required', 'in:A,B'],
            'status' => ['required', 'string'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
        ]);

        $photoPath = $this->photo?->store('employee-photos', 'public');

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make('Password@123'),
            'role' => UserRole::from($this->role),
        ]);

        $user->employee()->create([
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
            'probation_end_date' => $this->probation_end_date ?: null,
            'status' => $this->status,
            'employment_type' => $this->employment_type,
        ]);

        \Flux::toast(
            text: "Employee {$this->name} added successfully. Temporary password: Password@123",
            variant: 'success',
        );

        $this->js("setTimeout(() => { window.location = '".route('employees.index')."'; }, 2500)");

        // Persist initial leave balances for current year (if provided)
        foreach (\App\Models\LeaveType::all() as $lt) {
            $allocated = isset($this->leave_allocations[$lt->id]) ? (float) $this->leave_allocations[$lt->id] : 0.0;
            if ($allocated <= 0) {
                continue;
            }

            \App\Models\LeaveBalance::create([
                'employee_id' => $user->employee->id,
                'leave_type_id' => $lt->id,
                'year' => now()->year,
                'allocated_days' => $allocated,
                'used_days' => 0,
                'carried_forward_days' => 0,
                'encashed_days' => 0,
                'comp_off_credits' => 0,
            ]);
        }
    }

    public function render()
    {
        return view('livewire.employees.employee-create', [
            'offices' => Office::all(),
            'departments' => Department::all(),
            'jobTitles' => JobTitle::all(),
            'shifts' => ShiftSetting::all(),
            'managers' => User::whereIn('role', [
                UserRole::SuperAdmin,
                UserRole::HrAdmin,
                UserRole::Director,
                UserRole::Manager,
            ])->get(),
            'roles' => UserRole::cases(),
            'statuses' => EmployeeStatus::cases(),
            'employmentTypes' => EmploymentType::cases(),
        ])->layout('layouts.app', ['title' => 'Add Employee']);
    }
}
