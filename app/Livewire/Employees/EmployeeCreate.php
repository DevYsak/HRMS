<?php

namespace App\Livewire\Employees;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Mail\WelcomeEmployeeMail;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\JobTitle;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\ProbationSetting;
use App\Models\SalaryCycle;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\WorkMode;
use App\Notifications\WelcomeOnboardingNotification;
use App\Services\OnboardingService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public string $employee_code = '';

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

    public string $employment_type_id = '';

    public string $work_mode_id = '';

    public string $salary_cycle_id = '';

    public string $joining_date = '';

    public string $probation_end_date = '';

    public string $status = 'onboarding';

    public string $ot_tracking_source = 'biometric';

    public function mount(): void
    {
        $this->authorize('create', Employee::class);
        $this->joining_date = now()->format('Y-m-d');

        $lastId = Employee::max('id') ?? 0;
        $this->employee_id = 'CNX-'.str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);

        // Pre-select defaults
        $defaultCycle = SalaryCycle::where('is_default', true)->where('is_active', true)->first()
            ?? SalaryCycle::where('is_active', true)->first();
        $this->salary_cycle_id = (string) ($defaultCycle?->id ?? '');

        $defaultType = EmploymentType::where('slug', 'full-time')->where('is_active', true)->first()
            ?? EmploymentType::where('is_active', true)->first();
        $this->employment_type_id = (string) ($defaultType?->id ?? '');

        $this->recalculateProbation();
    }

    public function updatedDepartmentId(): void
    {
        if ($this->department_id) {
            $dept = Department::find($this->department_id);
            if ($dept) {
                $this->ot_tracking_source = $dept->default_ot_source ?? 'biometric';
            }
        }
    }

    public function updatedJoiningDate(): void
    {
        $this->recalculateProbation();
    }

    public function updatedEmploymentTypeId(): void
    {
        $this->recalculateProbation();
    }

    private function recalculateProbation(): void
    {
        if (! $this->joining_date) {
            return;
        }

        $typeId = $this->employment_type_id ?: null;

        $setting = ProbationSetting::when($typeId, fn ($q) => $q->where('employment_type_id', $typeId))
            ->where('is_active', true)
            ->when($typeId, fn ($q) => $q, fn ($q) => $q->whereNull('employment_type_id'))
            ->first()
            ?? ProbationSetting::whereNull('employment_type_id')->where('is_active', true)->first();

        $days = $setting?->probation_days
            ?? EmploymentType::find($typeId)?->probation_days
            ?? 90;

        $this->probation_end_date = now()->parse($this->joining_date)->addDays($days)->format('Y-m-d');
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
            'employee_code' => ['nullable', 'integer', 'min:1', 'max:65535', 'unique:employees,employee_code'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'in:male,female,other,prefer_not_to_say'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:2048'],
            'joining_date' => ['required', 'date'],
            'probation_end_date' => ['nullable', 'date', 'after:joining_date'],
            'shift_id' => ['nullable', 'exists:shift_settings,id'],
            'ot_tracking_source' => ['required', 'in:biometric,manual,nexflow,hybrid'],
            'employment_type_id' => ['nullable', 'exists:employment_types,id'],
            'work_mode_id' => ['nullable', 'exists:work_modes,id'],
            'salary_cycle_id' => ['nullable', 'exists:salary_cycles,id'],
            'status' => ['required', 'string'],
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
            'employee_code' => $this->employee_code !== '' ? (int) $this->employee_code : null,
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
            'ot_tracking_source' => $this->ot_tracking_source,
            'employment_type_id' => $this->employment_type_id ?: null,
            'work_mode_id' => $this->work_mode_id ?: null,
            'salary_cycle_id' => $this->salary_cycle_id ?: null,
            'joining_date' => $this->joining_date,
            'probation_end_date' => $this->probation_end_date ?: null,
            'status' => $this->status,
        ]);

        // Auto-complete account_create triggered onboarding tasks.
        app(OnboardingService::class)->autoComplete($user->employee, 'account_create', Auth::id());

        // Send welcome email with credentials and onboarding notification
        try {
            Mail::to($user->email)->send(new WelcomeEmployeeMail($user));
        } catch (\Throwable) {
            // Mail failure should not block employee creation
        }

        $user->notify(new WelcomeOnboardingNotification($user->employee));

        \Flux::toast(
            text: "Employee {$this->name} added successfully. Welcome email sent to {$this->email}.",
            variant: 'success',
        );

        $this->js("setTimeout(() => { window.location = '".route('employees.index')."'; }, 2500)");

        // Persist initial leave balances for current year (if provided)
        foreach (LeaveType::all() as $lt) {
            $allocated = isset($this->leave_allocations[$lt->id]) ? (float) $this->leave_allocations[$lt->id] : 0.0;
            if ($allocated <= 0) {
                continue;
            }

            LeaveBalance::create([
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
            'employmentTypes' => EmploymentType::active()->get(),
            'workModes' => WorkMode::active()->get(),
            'salaryCycles' => SalaryCycle::active()->get(),
        ])->layout('layouts.app', ['title' => 'Add Employee']);
    }
}
