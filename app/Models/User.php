<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Enums\ThemePreference;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'must_change_password', 'password_changed_at', 'last_login_at', 'current_team_id', 'avatar', 'role', 'role_id', 'scope_departments', 'scope_shifts', 'theme', 'timezone', 'date_format', 'time_format'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Keep `role_id` in sync with the legacy `role` enum so every code path
     * that assigns `role` (factories, onboarding, EmployeeEdit, etc.) keeps
     * working with the database-driven permission system without changes.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('role') && ! $user->isDirty('role_id') && $user->role !== null) {
                $user->role_id = Role::where('slug', $user->role->value)->value('id');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'theme' => ThemePreference::class,
            'scope_departments' => 'array',
            'scope_shifts' => 'array',
        ];
    }

    /**
     * Whether this approver acts company-wide (super admins always do, as does
     * any HR/manager with no department/shift scope set). When false, the
     * user's reach is narrowed to their scoped departments/shifts.
     */
    public function isCompanyWideApprover(): bool
    {
        return $this->isSuperAdmin()
            || $this->assignedRole?->slug === 'super_admin'
            || (empty($this->scope_departments) && empty($this->scope_shifts));
    }

    /** Does this user's attendance scope cover the given employee? */
    public function coversEmployee(Employee $employee): bool
    {
        if ($this->isCompanyWideApprover()) {
            return true;
        }

        $deptOk = empty($this->scope_departments) || in_array($employee->department_id, $this->scope_departments);
        $shiftOk = empty($this->scope_shifts) || in_array($employee->shift_id, $this->scope_shifts);

        return $deptOk && $shiftOk;
    }

    /**
     * Employee ids this user may see/approve, or NULL when company-wide (no
     * filter). Callers do `->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))`.
     *
     * @return array<int>|null
     */
    public function accessibleEmployeeIds(): ?array
    {
        if ($this->isCompanyWideApprover()) {
            return null;
        }

        return Employee::query()
            ->when($this->scope_departments, fn ($q, $d) => $q->whereIn('department_id', $d))
            ->when($this->scope_shifts, fn ($q, $s) => $q->whereIn('shift_id', $s))
            ->pluck('id')->all();
    }

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isHrAdmin(): bool
    {
        return $this->role === UserRole::HrAdmin;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager;
    }

    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * The role name to display — the real custom role name when one is
     * assigned (e.g. "Operations Manager"), falling back to the legacy
     * enum label for accounts without a DB role linked yet.
     */
    public function displayRoleName(): string
    {
        return $this->assignedRole?->name ?? $this->role?->label() ?? 'Member';
    }

    public function hasPermission(string $key): bool
    {
        // Super Admins always have every permission — even if no DB role was
        // ever linked to the account (role_id null). Without this, a manually
        // created super admin loses all permission-gated menus/features.
        if ($this->isSuperAdmin() || $this->assignedRole?->slug === 'super_admin') {
            return true;
        }

        return $this->assignedRole?->hasPermission($key) ?? false;
    }

    public function canManageEmployees(): bool
    {
        return $this->hasPermission('manage_employees');
    }

    public function canApproveLeave(): bool
    {
        return $this->hasPermission('approve_leave');
    }

    public function canRunPayroll(): bool
    {
        return $this->hasPermission('run_payroll');
    }

    public function canApproveOt(): bool
    {
        return $this->hasPermission('approve_overtime');
    }

    public function canApproveWfh(): bool
    {
        return $this->hasPermission('approve_wfh');
    }

    public function canApproveFinance(): bool
    {
        return $this->hasPermission('approve_finance');
    }

    public function canManageDocuments(): bool
    {
        return $this->hasPermission('manage_documents');
    }

    public function canManageSettings(): bool
    {
        return $this->hasPermission('manage_settings');
    }

    public function canViewReports(): bool
    {
        return $this->hasPermission('view_reports');
    }

    public function canViewFinanceProfile(): bool
    {
        return $this->hasPermission('view_finance_profile');
    }

    public function canLockPayroll(): bool
    {
        return $this->hasPermission('lock_payroll');
    }

    public function canUnlockPayroll(): bool
    {
        return $this->hasPermission('unlock_payroll');
    }

    public function canDeletePayslip(): bool
    {
        return $this->hasPermission('delete_payslip');
    }

    public function canReviewPerformance(): bool
    {
        return $this->hasPermission('review_performance');
    }

    public function avatarUrl(): string
    {
        if ($this->avatar) {
            return asset('storage/'.$this->avatar);
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($this->name).'&background=1DB77A&color=fff&size=128';
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function isDepartmentHead(): bool
    {
        return Department::where('head_id', $this->id)->exists();
    }
}
