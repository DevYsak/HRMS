<?php

namespace App\Services\Navigation;

use App\Enums\DataScope;
use App\Models\User;
use App\Services\Help\RouteAccess;
use App\Services\Security\ScopeResolver;

/**
 * Which dashboard a user's "Dashboard" opens, decided from what they hold —
 * never from the role's name — and only ever a page they can open.
 *
 *   Super Admin                         → the company overview at "/"
 *   HR (HR dashboard + employee mgmt)   → the HR overview at "/"
 *   Executive dashboard, company-wide   → /dashboard/director
 *   Heads a department                  → /dashboard/department
 *   Approves leave for a team           → /dashboard/manager
 *   Payroll / finance sign-off          → /dashboard/finance
 *   Anyone else                         → self-service at "/"
 */
class DashboardLanding
{
    public const COMPANY = 'company';

    public const HR = 'hr';

    public const SELF_SERVICE = 'self';

    public function __construct(private readonly RouteAccess $routeAccess) {}

    /** The route "/" forwards to, or null to render at "/" (see view()). */
    public function route(User $user): ?string
    {
        if ($this->view($user) !== self::SELF_SERVICE) {
            return null;
        }

        // People work before money: a department-scoped Director (who also
        // signs off finance) lands on their team, as before.
        $candidates = [
            'dashboard.director' => $user->hasPermission('view_executive_dashboard') && $user->isCompanyWideApprover(),
            'dashboard.department' => self::isDepartmentLevel($user),
            'dashboard.manager' => $user->canApproveLeave(),
            'dashboard.finance' => $user->canRunPayroll() || $user->canApproveFinance(),
        ];

        foreach ($candidates as $route => $applies) {
            if ($applies && $this->routeAccess->allows($user, $route)) {
                return $route;
            }
        }

        return null;
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
