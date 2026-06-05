<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Enums\ThemePreference;
use App\Enums\UserRole;
use App\Services\RolePermissionService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'current_team_id', 'avatar', 'role', 'theme'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

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

    public function canManageEmployees(): bool
    {
        return RolePermissionService::check($this->role?->value, 'manage-employees');
    }

    public function canApproveLeave(): bool
    {
        return RolePermissionService::check($this->role?->value, 'approve-leave');
    }

    public function canRunPayroll(): bool
    {
        return RolePermissionService::check($this->role?->value, 'run-payroll');
    }

    public function canApproveOt(): bool
    {
        return RolePermissionService::check($this->role?->value, 'approve-ot');
    }

    public function canApproveFinance(): bool
    {
        return RolePermissionService::check($this->role?->value, 'approve-finance');
    }

    public function canManageDocuments(): bool
    {
        return RolePermissionService::check($this->role?->value, 'manage-documents');
    }

    public function canManageSettings(): bool
    {
        return RolePermissionService::check($this->role?->value, 'manage-settings');
    }

    public function canViewReports(): bool
    {
        return RolePermissionService::check($this->role?->value, 'view-reports');
    }

    public function canViewFinanceProfile(): bool
    {
        return RolePermissionService::check($this->role?->value, 'view-finance-profile');
    }

    public function canReviewPerformance(): bool
    {
        return RolePermissionService::check($this->role?->value, 'review-performance');
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
