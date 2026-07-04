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

#[Fillable(['name', 'email', 'password', 'current_team_id', 'avatar', 'role', 'role_id', 'theme', 'timezone', 'date_format', 'time_format'])]
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
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'theme' => ThemePreference::class,
        ];
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
