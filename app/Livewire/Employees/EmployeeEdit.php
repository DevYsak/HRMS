<?php

namespace App\Livewire\Employees;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\JobTitle;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\SalaryComponent;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\ProbationExtendedNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
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
    public string $biometric_id = '';

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

    // Leave allocations keyed by leave_type_id (editable)
    public array $leave_allocations = [];

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
        $this->biometric_id = $this->employee->biometric_id ?? '';
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

        // Load existing leave allocations for editing
        $this->leave_allocations = [];
        foreach (LeaveType::all() as $lt) {
            $bal = LeaveBalance::where('employee_id', $this->employee->id)
                ->where('leave_type_id', $lt->id)
                ->where('year', now()->year)
                ->first();

            $this->leave_allocations[$lt->id] = $bal?->allocated_days ?? 0;
        }
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
            'biometric_id' => ['nullable', 'string', 'max:20', Rule::unique('employees', 'biometric_id')->ignore($this->employee->id)],
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
            'biometric_id' => $this->biometric_id ?: null,
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

        \Flux::toast(text: 'Employee updated successfully.', variant: 'success');

        // Persist leave allocations for current year
        foreach (LeaveType::all() as $lt) {
            $allocated = isset($this->leave_allocations[$lt->id]) ? (float) $this->leave_allocations[$lt->id] : 0.0;

            LeaveBalance::updateOrCreate([
                'employee_id' => $this->employee->id,
                'leave_type_id' => $lt->id,
                'year' => now()->year,
            ], [
                'allocated_days' => $allocated,
                'used_days' => 0,
                'carried_forward_days' => 0,
                'encashed_days' => 0,
                'comp_off_credits' => 0,
            ]);
        }

        $this->js("setTimeout(() => { window.location = '".route('employees.index')."'; }, 1500)");
    }

    // ── Email modal ──────────────────────────────────────────────────────────
    public bool $showEmailModal = false;

    public string $emailSubject = '';

    public string $emailBody = '';

    public ?string $localResetUrl = null;

    // ── Salary modal ─────────────────────────────────────────────────────────
    public bool $showSalaryModal = false;

    public ?int $editingSalaryId = null;

    public string $salaryComponentId = '';

    public string $salaryAmount = '';

    // ── Header actions ────────────────────────────────────────────────────────

    public function resetPassword(): void
    {
        $this->authorize('update', $this->employee);

        try {
            $this->localResetUrl = null;

            if (app()->isLocal() && in_array(config('mail.default'), ['log', 'array'], true)) {
                $token = Password::broker(config('fortify.passwords'))->createToken($this->employee->user);

                $this->localResetUrl = route('password.reset', [
                    'token' => $token,
                    'email' => $this->employee->user->email,
                ]);

                \Flux::toast('Reset link generated for local use. Mail is using the '.config('mail.default').' driver.', variant: 'warning');

                return;
            }

            $status = Password::sendResetLink(['email' => $this->employee->user->email]);

            if ($status !== Password::RESET_LINK_SENT) {
                \Flux::toast(__($status), variant: 'danger');

                return;
            }

            \Flux::toast('Password reset link sent to '.$this->employee->user->email, variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast('Could not send reset email: '.$e->getMessage(), variant: 'danger');
        }
    }

    public function openEmailModal(): void
    {
        $this->emailSubject = '';
        $this->emailBody = '';
        $this->resetErrorBag();
        $this->showEmailModal = true;
    }

    public function closeEmailModal(): void
    {
        $this->showEmailModal = false;
        $this->emailSubject = '';
        $this->emailBody = '';
        $this->resetErrorBag();
    }

    public function sendEmail(): void
    {
        $this->authorize('update', $this->employee);

        $this->validate([
            'emailSubject' => ['required', 'string', 'max:255'],
            'emailBody' => ['required', 'string', 'max:5000'],
        ]);

        $to = $this->employee->user->email;
        $subject = $this->emailSubject;
        $body = $this->emailBody;

        try {
            Mail::raw($body, function ($msg) use ($to, $subject) {
                $msg->to($to)->subject($subject);
            });
            \Flux::toast('Email sent to '.$to, variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast('Could not send email: '.$e->getMessage(), variant: 'danger');
        }

        $this->closeEmailModal();
    }

    public function deactivate(): void
    {
        $this->authorize('update', $this->employee);

        $this->employee->update(['status' => EmployeeStatus::Inactive]);
        $this->status = EmployeeStatus::Inactive->value;

        \Flux::toast('Employee has been deactivated.', variant: 'warning');
    }

    // ── Salary management ────────────────────────────────────────────────────

    public function openAddSalary(): void
    {
        $this->editingSalaryId = null;
        $this->salaryComponentId = '';
        $this->salaryAmount = '';
        $this->showSalaryModal = true;
        $this->dispatch('modal-show', name: 'salary-modal');
    }

    public function openEditSalary(int $id): void
    {
        $row = EmployeeSalary::findOrFail($id);
        $this->editingSalaryId = $id;
        $this->salaryComponentId = (string) $row->salary_component_id;
        $this->salaryAmount = (string) $row->amount;
        $this->showSalaryModal = true;
        $this->dispatch('modal-show', name: 'salary-modal');
    }

    public function saveSalary(): void
    {
        $this->authorize('update', $this->employee);

        $this->validate([
            'salaryComponentId' => ['required', 'exists:salary_components,id'],
            'salaryAmount' => ['required', 'numeric', 'min:0'],
        ]);

        if ($this->editingSalaryId) {
            EmployeeSalary::findOrFail($this->editingSalaryId)->update([
                'salary_component_id' => $this->salaryComponentId,
                'amount' => $this->salaryAmount,
            ]);
        } else {
            EmployeeSalary::create([
                'employee_id' => $this->employee->id,
                'salary_component_id' => $this->salaryComponentId,
                'amount' => $this->salaryAmount,
            ]);
        }

        $this->employee->load('salaries.component');
        $this->showSalaryModal = false;
        $this->dispatch('modal-close', name: 'salary-modal');
        \Flux::toast('Salary component saved.', variant: 'success');
    }

    public function removeSalary(int $id): void
    {
        $this->authorize('update', $this->employee);

        EmployeeSalary::where('employee_id', $this->employee->id)->findOrFail($id)->delete();
        $this->employee->load('salaries.component');
        \Flux::toast('Salary component removed.', variant: 'success');
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
            'salaryComponents' => SalaryComponent::where('is_active', true)->orderBy('type')->orderBy('name')->get(),
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
