<?php

namespace App\Services\Navigation;

use App\Enums\DataScope;
use App\Models\User;
use App\Services\Security\ScopeResolver;

/**
 * What the Main Dashboard at "/" renders for a user — decided from what they
 * hold, never from the role's name:
 *
 *   Super Admin                         → the company overview
 *   HR (HR overview + employee mgmt)    → the HR overview
 *   Anyone else                         → employee self-service
 *
 * Which dashboard "/" lands on, and whether the user may open each one, is
 * DashboardAccess (the Dashboard Access permissions).
 */
class DashboardLanding
{
    public const COMPANY = 'company';

    public const HR = 'hr';

    public const SELF_SERVICE = 'self';

    /**
     * The route "/" forwards to, or null to render at "/" (see view()). The
     * order is DashboardAccess::landing(), from the Dashboard Access
     * permissions in Roles & Permissions.
     */
    public function route(User $user): ?string
    {
        $landing = app(DashboardAccess::class)->landing($user);

        return $landing === 'dashboard' ? null : $landing;
    }

    /**
     * Whether the user works at department level: heads a department, or
     * their attendance reach is a department / chosen departments (a
     * Department Head, or a Director under D1). Decided by scope, not role.
     */
    public static function isDepartmentLevel(User $user): bool
    {
        if ($user->isDepartmentHead()) {
            return true;
        }

        return $user->hasPermission('view_attendance')
            && in_array(app(ScopeResolver::class)->scopeFor($user, 'view_attendance'), [DataScope::Department, DataScope::SelectedDepartments], true);
    }

    /** What "/" itself renders for the user when it does not forward. */
    public function view(User $user): string
    {
        if ($user->isSuperAdmin() || $user->assignedRole?->slug === 'super_admin') {
            return self::COMPANY;
        }

        if ($user->hasPermission('view_hr_dashboard') && $user->canManageEmployees()) {
            return self::HR;
        }

        return self::SELF_SERVICE;
    }
}
