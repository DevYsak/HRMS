<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Enums\ThemePreference;
use App\Enums\UserRole;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Services\Approvals\ApprovalGuard;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'password_changed_at', 'last_login_at', 'current_team_id', 'avatar', 'role', 'role_id', 'scope_departments', 'scope_shifts', 'theme', 'timezone', 'date_format', 'time_format'])]
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
     * Still on an issued credential: confined to the "Set your password" page
     * until the owner chooses their own.
     *
     * @see EnsurePasswordChanged
     */
    public function requiresPasswordChange(): bool
    {
        return (bool) $this->must_change_password;
    }

    /**
     * Whether this user reaches every employee. Fails closed: only Super
     * Admins and unscoped employee-management roles (HR Admin, Director)
     * are company-wide — an unscoped manager is NOT.
     *
     * @see ApprovalGuard
     */
    public function isCompanyWideApprover(): bool
    {
        return app(ApprovalGuard::class)->isCompanyWide($this);
    }

    /**
     * Does this user's scope or reporting line cover the given employee?
     * With a permission, that permission's configured data scope applies.
     */
    public function coversEmployee(Employee $employee, ?string $permission = null): bool
    {
        return app(ApprovalGuard::class)->covers($this, $employee, $permission);
    }

    /**
     * Employee ids this user may see/approve, or NULL when company-wide (no
     * filter). Callers do `->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))`.
     *
     * @return array<int>|null
     */
    public function accessibleEmployeeIds(?string $permission = null): ?array
    {
        return app(ApprovalGuard::class)->accessibleEmployeeIds($this, $permission);
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

        if ($override = $this->permissionOverride($key)) {
            return $override['effect'] === UserPermissionOverride::GRANT;
        }

        return $this->effectiveRole()?->hasPermission($key) ?? false;
    }

    /**
     * The role whose permissions apply. A deactivated custom role grants
     * nothing beyond Employee self-service, so its members keep their own
     * leave, payslips and profile but lose everything the role added.
     */
    public function effectiveRole(): ?Role
    {
        $role = $this->assignedRole;

        if ($role === null || $role->is_active) {
            return $role;
        }

        return Role::where('slug', UserRole::Employee->value)->first();
    }

    /**
     * Every permission key the user holds: the role's, plus per-user grants,
     * minus per-user revocations.
     *
     * @return array<int, string>
     */
    public function effectivePermissionKeys(): array
    {
        if ($this->isSuperAdmin() || $this->assignedRole?->slug === 'super_admin') {
            return Permission::pluck('key')->all();
        }

        $keys = $this->effectiveRole()?->permissionKeys() ?? [];

        foreach ($this->permissionOverrideMap() as $key => $override) {
            $keys = $override['effect'] === UserPermissionOverride::GRANT
                ? [...$keys, $key]
                : array_diff($keys, [$key]);
        }

        return array_values(array_unique($keys));
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    /**
     * This user's override for one permission, if any.
     *
     * @return array{effect: string, scope: ?string, department_ids: array<int, int>}|null
     */
    public function permissionOverride(string $key): ?array
    {
        return $this->permissionOverrideMap()[$key] ?? null;
    }

    /**
     * Per-user overrides keyed by permission key (cached; flushed whenever
     * an override is saved or deleted).
     *
     * @return array<string, array{effect: string, scope: ?string, department_ids: array<int, int>}>
     */
    public function permissionOverrideMap(): array
    {
        if ($this->id === null) {
            return [];
        }

        return Cache::remember(
            UserPermissionOverride::cacheKey($this->id),
            300,
            fn () => UserPermissionOverride::query()
                ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
                ->where('user_permission_overrides.user_id', $this->id)
                ->get(['permissions.key', 'user_permission_overrides.effect', 'user_permission_overrides.scope', 'user_permission_overrides.department_ids'])
                ->mapWithKeys(fn (UserPermissionOverride $o) => [$o->key => [
                    'effect' => $o->effect,
                    // The stored string, not the enum cast: an unrecognised value must
                    // reach ScopeResolver and fail closed to no access, not throw here.
                    'scope' => $o->getRawOriginal('scope'),
                    'department_ids' => array_map('intval', $o->department_ids ?? []),
                ]])
                ->all(),
        );
    }

    public function canManageEmployees(): bool
    {
        return $this->hasPermission('manage_employees');
    }

    /**
     * Whether this user may "Login as" the target: the Login as Employee
     * permission, never themselves, never an account that is locked out of
     * signing in, and — for anyone but a Super Admin — never a Super Admin
     * and only people inside their own reach.
     */
    public function canImpersonate(User $target): bool
    {
        if ($target->id === $this->id || ! $this->hasPermission('impersonate') || $target->isLockedOut()) {
            return false;
        }

        if ($this->isSuperAdmin() || $this->assignedRole?->slug === 'super_admin') {
            return true;
        }

        if ($target->isSuperAdmin() || $target->assignedRole?->slug === 'super_admin') {
            return false;
        }

        return $target->employee !== null && $this->coversEmployee($target->employee);
    }

    /**
     * Whether CheckActiveEmployee would sign this account straight out: an
     * inactive employee, or one past their last working day. Viewing as such
     * an account would end the impersonator's own session instead.
     */
    public function isLockedOut(): bool
    {
        $employee = $this->employee;

        if ($employee === null) {
            return false;
        }

        if ($employee->status?->value === 'inactive') {
            return true;
        }

        $lastWorkingDay = $employee->exitRecord?->last_working_day;

        return $lastWorkingDay !== null && now()->greaterThan(Carbon::parse($lastWorkingDay)->endOfDay());
    }

    /**
     * Whether this user decides attendance / leave regularisations — HR, by
     * the "Approve Regularisations (HR)" permission (Super Admin always).
     * An approval applies the correction in one step.
     */
    public function canApproveRegularisations(): bool
    {
        return $this->hasPermission('hr_approve_regularisation');
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

    /**
     * Whether HR has narrowed this account to departments or shifts. A scoped
     * account is never shown company-wide figures, whatever its role.
     */
    public function isDepartmentScoped(): bool
    {
        return ! empty($this->scope_departments) || ! empty($this->scope_shifts);
    }
}
