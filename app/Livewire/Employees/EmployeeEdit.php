<?php

namespace App\Livewire\Employees;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Mail\WelcomeEmployeeMail;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\EmploymentType;
use App\Models\JobTitle;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\SalaryCycle;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\WorkMode;
use App\Services\Biometric\BiometricCodeService;
use App\Services\Biometric\EngineAttendanceSyncService;
use App\Services\LeaveBalanceService;
use App\Services\PasswordService;
use App\Services\ProbationEngine;
use Illuminate\Support\Facades\Auth;
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

    // ── Temp-password reveal (shown once) ──
    public bool $showCredentialsModal = false;

    public string $generatedPassword = '';

    // ── Account ──────────────────────────────────────────────────────────────
    public string $name = '';

    public string $email = '';

    public string $roleId = '';

    /** HR/manager access scope — empty = company-wide. @var array<int> */
    public array $scopeDepartments = [];

    /** @var array<int> */
    public array $scopeShifts = [];

    // ── Personal profile ─────────────────────────────────────────────────────
    public string $employee_id = '';

    public string $phone = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $address = '';

    public string $emergency_contact = '';

    public $photo = null;

    // ── Employment ───────────────────────────────────────────────────────────
    // Device PIN — the canonical biometric matching key (employee_code).
    // Mirrored to biometric_id on save so every attendance ingestion path matches.
    public string $employee_code = '';

    /** How many days back the "Sync latest" button pulls from the engine. */
    public int $syncDays = 7;

    public string $office_id = '';

    public string $department_id = '';

    public string $job_title_id = '';

    public string $manager_id = '';

    public string $shift_id = '';

    public string $employment_type_id = '';

    public string $work_mode_id = '';

    public string $salary_cycle_id = '';

    public string $joining_date = '';

    public string $status = '';

    // ── Lifecycle date fields (Phase 1A) ─────────────────────────────────────
    public string $resignation_date = '';

    public string $termination_date = '';

    public string $notice_period_end_date = '';

    // ── Probation ────────────────────────────────────────────────────────────
    public string $probation_end_date = '';

    public string $extend_end_date = '';

    public string $extend_reason = '';

    public string $ot_tracking_source = 'biometric';

    /** Set when the typed Device ID is held by someone else — drives the Reassign prompt. */
    public ?string $codeConflict = null;

    // ── Leave Balance Manage Modal ────────────────────────────────────────────
    public bool $showManageLeaveModal = false;

    public string $leaveAdjustTypeId = '';

    public string $leaveAdjustAction = 'credit';

    public string $leaveAdjustDays = '';

    public string $leaveAdjustReason = '';

    public string $leaveAdjustRemarks = '';

    public string $leaveBalanceYear = '';

    public function mount(Employee $employee): void
    {
        $this->authorize('update', $employee);
        $this->employee = $employee->load('user');

        // Account
        $this->name = $this->employee->user->name;
        $this->email = $this->employee->user->email;
        $this->roleId = (string) ($this->employee->user->role_id
            ?? Role::where('slug', $this->employee->user->role->value)->value('id')
            ?? '');
        $this->scopeDepartments = array_map('intval', $this->employee->user->scope_departments ?? []);
        $this->scopeShifts = array_map('intval', $this->employee->user->scope_shifts ?? []);

        // Personal
        $this->employee_id = $this->employee->employee_id ?? '';
        $this->phone = $this->employee->phone ?? '';
        $this->date_of_birth = $this->employee->date_of_birth?->format('Y-m-d') ?? '';
        $this->gender = $this->employee->gender ?? '';
        $this->address = $this->employee->address ?? '';
        $this->emergency_contact = $this->employee->emergency_contact ?? '';

        // Employment
        // Prefer employee_code; fall back to legacy biometric_id so older records
        // (PIN saved only to biometric_id) still display and self-heal on next save.
        $this->employee_code = (string) ($this->employee->employee_code ?? $this->employee->biometric_id ?? '');
        $this->office_id = (string) ($this->employee->office_id ?? '');
        $this->department_id = (string) ($this->employee->department_id ?? '');
        $this->job_title_id = (string) ($this->employee->job_title_id ?? '');
        $this->manager_id = (string) ($this->employee->manager_id ?? '');
        $this->shift_id = (string) ($this->employee->shift_id ?? '');
        $this->employment_type_id = (string) ($this->employee->employment_type_id ?? '');
        $this->work_mode_id = (string) ($this->employee->work_mode_id ?? '');
        $this->salary_cycle_id = (string) ($this->employee->salary_cycle_id ?? '');
        // Nullable since the HR data migration — an imported employee may not
        // have a joining date yet, and the edit screen is where HR fills it in.
        $this->joining_date = $this->employee->joining_date?->format('Y-m-d') ?? '';
        $this->probation_end_date = $this->employee->probation_end_date?->format('Y-m-d') ?? '';
        $this->status = $this->employee->status->value;
        $this->ot_tracking_source = $this->employee->ot_tracking_source ?? 'biometric';
        // Lifecycle dates
        $this->resignation_date = $this->employee->resignation_date?->format('Y-m-d') ?? '';
        $this->termination_date = $this->employee->termination_date?->format('Y-m-d') ?? '';
        $this->notice_period_end_date = $this->employee->notice_period_end_date?->format('Y-m-d') ?? '';
        $this->leaveBalanceYear = (string) now()->year;
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
            'roleId' => ['required', Rule::exists('roles', 'id')->where('is_active', true)],
            // Personal
            // whereNull('deleted_at') on both identity columns: Rule::unique
            // queries the raw table, so without it a soft-deleted employee
            // reserves their Employee ID and Device ID forever and HR is told
            // the value is taken by somebody who is not in the directory.
            'employee_id' => ['required', 'string', Rule::unique('employees', 'employee_id')->whereNull('deleted_at')->ignore($this->employee->id)],
            'phone' => 'nullable|string|max:30',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|string|in:male,female,other,prefer_not_to_say',
            'address' => 'nullable|string|max:500',
            'emergency_contact' => 'nullable|string|max:255',
            'photo' => 'nullable|image|max:2048',
            // Employment
            'employee_code' => ['nullable', 'integer', 'min:1', 'max:65535', Rule::unique('employees', 'employee_code')->whereNull('deleted_at')->ignore($this->employee->id)],
            // Nullable so HR can still edit an employee imported without a
            // joining date; this screen is where they fill it in.
            'joining_date' => 'nullable|date',
            'shift_id' => 'nullable|exists:shift_settings,id',
            'ot_tracking_source' => 'required|in:biometric,manual,nexflow,hybrid',
            'employment_type_id' => 'nullable|exists:employment_types,id',
            'work_mode_id' => 'nullable|exists:work_modes,id',
            'salary_cycle_id' => 'nullable|exists:salary_cycles,id',
            'status' => 'required|string',
            'resignation_date' => 'nullable|date',
            'termination_date' => 'nullable|date',
            'notice_period_end_date' => 'nullable|date',
        ]);

        $chosenRole = Role::findOrFail($this->roleId);

        // HR/manager access scope only applies to approver roles; it's cleared
        // for everyone else so a normal employee can never carry a stray scope.
        $scopesApply = in_array($chosenRole->legacyBucket()->value, ['hr_admin', 'manager'], true);

        $this->employee->user->update([
            'name' => $this->name,
            'email' => $this->email,
            'role' => $chosenRole->legacyBucket(),
            'role_id' => $chosenRole->id,
            'scope_departments' => $scopesApply && $this->scopeDepartments ? array_map('intval', $this->scopeDepartments) : null,
            'scope_shifts' => $scopesApply && $this->scopeShifts ? array_map('intval', $this->scopeShifts) : null,
        ]);

        $photoPath = $this->photo
            ? $this->photo->store('employee-photos', 'public')
            : $this->employee->photo;

        $this->employee->update([
            'employee_id' => $this->employee_id,
            'employee_code' => $this->employee_code !== '' ? (int) $this->employee_code : null,
            'biometric_id' => $this->employee_code !== '' ? $this->employee_code : null,
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
            'joining_date' => $this->joining_date ?: null,
            'status' => $this->status,
            'resignation_date' => $this->resignation_date ?: null,
            'termination_date' => $this->termination_date ?: null,
            'notice_period_end_date' => $this->notice_period_end_date ?: null,
        ]);

        \Flux::toast(text: 'Employee updated successfully.', variant: 'success');
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

            $broker = config('fortify.passwords', 'users');
            $status = Password::broker($broker)->sendResetLink(['email' => $this->employee->user->email]);

            if ($status !== Password::RESET_LINK_SENT) {
                \Flux::toast(__($status), variant: 'danger');

                return;
            }

            \Flux::toast('Password reset link sent to '.$this->employee->user->email, variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast('Could not send reset email: '.$e->getMessage(), variant: 'danger');
        }
    }

    public function setTemporaryPassword(): void
    {
        $this->authorize('update', $this->employee);

        $user = $this->employee->user;

        // Secure random password (records history); replaces the old weak Temp@NNNNN.
        $tempPassword = app(PasswordService::class)->resetPassword($user, null, Auth::user());

        try {
            Mail::to($user->email)->send(new WelcomeEmployeeMail($user, $tempPassword));
        } catch (\Throwable $e) {
            \Flux::toast('Password reset but email failed: '.$e->getMessage(), variant: 'warning');
        }

        // Reveal once so HR/Admin can copy and share it.
        $this->generatedPassword = $tempPassword;
        $this->showCredentialsModal = true;
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

    public function reactivate(): void
    {
        $this->authorize('update', $this->employee);

        $this->employee->update(['status' => EmployeeStatus::Active]);
        $this->status = EmployeeStatus::Active->value;

        \Flux::toast('Employee has been reactivated.', variant: 'success');
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

        try {
            app(ProbationEngine::class)->extend(
                $this->employee,
                auth()->user(),
                $this->extend_end_date,
                $this->extend_reason,
            );

            $this->probation_end_date = $this->extend_end_date;
            $this->reset('extend_end_date', 'extend_reason');
            \Flux::toast('Probation extended and manager notified.', variant: 'success');
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    // ── Biometric Device ID ───────────────────────────────────────────────────

    /**
     * Warn as soon as the typed Device ID collides, naming the holder.
     *
     * "Already taken" on save is a dead end when the holder is a deleted
     * employee nobody can find; this says who has it and offers the override.
     */
    public function updatedEmployeeCode(BiometricCodeService $codes): void
    {
        $this->codeConflict = $codes->conflictMessage($this->employee_code, $this->employee->id);
    }

    /**
     * Move the Device ID onto this employee, clearing whoever holds it.
     *
     * The deliberate override for the two cases HR actually hits: a card handed
     * to a new person, and a deleted employee still squatting on the number.
     * Both sides are audited because losing a Device ID silently would stop the
     * previous holder's punches from matching.
     */
    public function reassignBiometricCode(BiometricCodeService $codes): void
    {
        abort_unless(auth()->user()->canManageEmployees(), 403);

        if ($this->employee_code === '') {
            return;
        }

        $codes->reassign($this->employee, $this->employee_code, auth()->user());

        $this->employee->refresh();
        $this->codeConflict = null;

        \Flux::toast("Biometric Device ID {$this->employee_code} reassigned to this employee.", variant: 'success');
    }

    // ── Biometric sync ────────────────────────────────────────────────────────

    /**
     * Pull the latest computed attendance from the Python engine for the last
     * N days and report what landed for THIS employee. The engine matches on
     * employee_code (the Biometric Device ID above), so this is the quickest way
     * to verify a mapping and backfill someone's log after enrolling them.
     * Delegates to the existing engine sync service — no sync logic changes here.
     */
    public function syncBiometricNow(EngineAttendanceSyncService $engine): void
    {
        abort_unless(auth()->user()->canManageEmployees(), 403);

        if (! $this->employee->employee_code) {
            \Flux::toast('Set the Biometric Device ID first, then save — the engine matches punches by that code.', variant: 'warning');

            return;
        }

        $days = max(1, min(30, (int) $this->syncDays));
        $synced = 0;
        $errors = [];

        for ($i = 0; $i < $days; $i++) {
            $result = $engine->syncDate(now()->subDays($i)->toDateString());
            $synced += (int) ($result['synced'] ?? 0);
            if (! empty($result['error'])) {
                $errors[] = $result['error'];
            }
        }

        if ($errors !== []) {
            \Flux::toast('Engine sync problem: '.$errors[0], variant: 'danger');

            return;
        }

        $this->employee->refresh();
        $mine = Attendance::where('employee_id', $this->employee->id)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->count();

        \Flux::toast("Synced the last {$days} day(s) from the engine — {$synced} record(s) processed, {$mine} day(s) now on this employee.");
    }

    // ── Manage Leave Balance ──────────────────────────────────────────────────

    public function openManageLeaveModal(): void
    {
        abort_unless(auth()->user()->isHrAdmin() || auth()->user()->isSuperAdmin(), 403);

        $this->reset(['leaveAdjustTypeId', 'leaveAdjustDays', 'leaveAdjustReason', 'leaveAdjustRemarks']);
        $this->leaveAdjustAction = 'credit';
        $this->showManageLeaveModal = true;
    }

    public function closeManageLeaveModal(): void
    {
        $this->showManageLeaveModal = false;
        $this->resetErrorBag();
    }

    public function submitLeaveAdjustment(): void
    {
        abort_unless(auth()->user()->isHrAdmin() || auth()->user()->isSuperAdmin(), 403);

        $this->validate([
            'leaveAdjustTypeId' => 'required|exists:leave_types,id',
            'leaveAdjustAction' => 'required|in:credit,debit',
            'leaveAdjustDays' => 'required|numeric|min:0.5|max:365',
            'leaveAdjustReason' => 'required|string|min:5|max:500',
            'leaveAdjustRemarks' => 'nullable|string|max:1000',
        ]);

        $leaveType = LeaveType::findOrFail($this->leaveAdjustTypeId);

        try {
            app(LeaveBalanceService::class)->adjust(
                $this->employee,
                $leaveType,
                $this->leaveAdjustAction,
                (float) $this->leaveAdjustDays,
                $this->leaveAdjustReason,
                $this->leaveAdjustRemarks,
                auth()->user(),
                (int) $this->leaveBalanceYear,
            );
        } catch (\DomainException $e) {
            $this->addError('leaveAdjustDays', $e->getMessage());

            return;
        }

        $this->closeManageLeaveModal();
        \Flux::toast(
            ucfirst($this->leaveAdjustAction).' of '.$this->leaveAdjustDays.' day(s) applied successfully.',
            variant: 'success',
        );
    }

    public function render()
    {
        $this->employee->load(['salaries.component', 'shift']);

        $leaveService = app(LeaveBalanceService::class);
        $balanceSummary = $this->activeTab === 'Leave'
            ? $leaveService->getBalanceSummary($this->employee, (int) $this->leaveBalanceYear)
            : collect();
        $adjustmentHistory = ($this->activeTab === 'Leave')
            ? $leaveService->getAdjustmentHistory($this->employee)
            : collect();

        // ── Phase 4: Profile 2.0 analytical tabs (loaded only for the active tab) ──
        $tab = $this->activeTab;
        $attendanceRecords = $tab === 'Attendance'
            ? $this->employee->attendances()->latest('date')->limit(30)->get()
            : collect();
        $reviewHistory = $tab === 'Performance'
            ? $this->employee->performanceReviews()->with('cycle')->latest('id')->get()
            : collect();
        $kpiHistory = $tab === 'Performance'
            ? $this->employee->scorecards()->with('cycle')->latest('id')->get()
            : collect();
        $otRecords = $tab === 'OT'
            ? $this->employee->overtimeRecords()->latest('work_date')->limit(30)->get()
            : collect();
        $warningHistory = $tab === 'Warnings'
            ? $this->employee->warningLetters()->latest('id')->get()
            : collect();
        $pipHistory = $tab === 'PIP'
            ? $this->employee->pipRecords()->latest('id')->get()
            : collect();
        $promotionHistory = $tab === 'Promotions'
            ? $this->employee->promotionRecommendations()->latest('id')->get()
            : collect();
        $timeline = $tab === 'Timeline'
            ? $this->employee->performanceTimelines()->get()
            : collect();

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
            'roles' => Role::where('is_active', true)->orderBy('name')->get(),
            'statuses' => EmployeeStatus::cases(),
            'employmentTypes' => EmploymentType::active()->get(),
            'workModes' => WorkMode::active()->get(),
            'salaryCycles' => SalaryCycle::active()->get(),
            'balanceSummary' => $balanceSummary,
            'adjustmentHistory' => $adjustmentHistory,
            'adjustableLeaveTypes' => LeaveType::where('is_system_controlled', false)->whereNull('deleted_at')->orderBy('name')->get(),
            'canManageLeaveBalance' => auth()->user()->isHrAdmin() || auth()->user()->isSuperAdmin(),
            'attendanceRecords' => $attendanceRecords,
            'reviewHistory' => $reviewHistory,
            'kpiHistory' => $kpiHistory,
            'otRecords' => $otRecords,
            'warningHistory' => $warningHistory,
            'pipHistory' => $pipHistory,
            'promotionHistory' => $promotionHistory,
            'timeline' => $timeline,
        ])->layout('layouts.app', ['title' => 'Edit Employee']);
    }
}
