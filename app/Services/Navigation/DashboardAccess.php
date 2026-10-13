<?php

namespace App\Services\Navigation;

use App\Enums\DataScope;
use App\Models\User;
use App\Services\Help\RouteAccess;
use App\Services\ModuleFeatureService;
use App\Services\Security\PermissionScopes;
use App\Services\Security\ScopeResolver;

/**
 * Which dashboards a user may open, and which one they land on — decided by
 * the Dashboard Access permissions in Roles & Permissions, never by the
 * role's name. The routes (gates `open-*-dashboard`), the sidebar and the
 * post-login landing all ask this class, so a hidden dashboard is also a
 * closed URL.
 *
 * Team View and Department View are scoped: the scope says at which level the
 * view may open (Team View: team, department or company; Department View:
 * department or company). Any other scope — own records, none, or a value
 * the system does not know — closes the view. The people each view lists
 * still follow its own module scope (Approve Leave / View Attendance).
 */
class DashboardAccess
{
    public const MAIN = 'main';

    public const TEAM = 'team';

    public const DEPARTMENT = 'department';

    public const EXECUTIVE = 'executive';

    public const FINANCE = 'finance';

    /** @var array<string, array{permission: string, route: string, label: string}> */
    public const DASHBOARDS = [
        self::MAIN => ['permission' => 'view_main_dashboard', 'route' => 'dashboard.main', 'label' => 'Main Dashboard'],
        self::TEAM => ['permission' => 'view_team_dashboard', 'route' => 'dashboard.manager', 'label' => 'Team View'],
        self::DEPARTMENT => ['permission' => 'view_department_dashboard', 'route' => 'dashboard.department', 'label' => 'Department View'],
        self::EXECUTIVE => ['permission' => 'view_executive_dashboard', 'route' => 'dashboard.executive', 'label' => 'Executive View'],
        self::FINANCE => ['permission' => 'view_finance_dashboard', 'route' => 'dashboard.finance', 'label' => 'Finance View'],
    ];

    /** Pages a user without any dashboard is sent to, first one they can open. */
    private const FALLBACK_ROUTES = ['attendance.my', 'time-off.my', 'profile.edit'];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly RouteAccess $routes,
    ) {}

    public function allows(User $user, string $dashboard): bool
    {
        $definition = self::DASHBOARDS[$dashboard] ?? null;

        if ($definition === null || ! $user->hasPermission($definition['permission'])) {
            return false;
        }

        $allowed = PermissionScopes::allowedScopes($definition['permission']);
        if ($allowed !== null && ! in_array($this->scopes->scopeFor($user, $definition['permission']), $allowed, true)) {
            return false;
        }

        return match ($dashboard) {
            // Company-wide figures: only for someone whose approvals reach the company.
            self::EXECUTIVE => $user->isCompanyWideApprover(),
            self::FINANCE => app(ModuleFeatureService::class)->payrollEnabled(),
            default => true,
        };
    }

    /** @return array<int, string> the dashboards the user may open, in menu order */
    public function permitted(User $user): array
    {
        return array_values(array_filter(array_keys(self::DASHBOARDS), fn (string $key) => $this->allows($user, $key)));
    }

    /**
     * Where "/" and the login send the user:
     *
     *   Main Dashboard, when it is a company / HR overview for this user
     *   → Department View → Team View → Main Dashboard (self-service)
     *   → Executive View → Finance View → the first safe page they can open.
     *
     * Returns 'dashboard' when the user lands on the main dashboard at "/".
     */
    public function landing(User $user): ?string
    {
        $mainIsOverview = app(DashboardLanding::class)->view($user) !== DashboardLanding::SELF_SERVICE;

        $order = $mainIsOverview
            ? [self::MAIN, self::DEPARTMENT, self::TEAM, self::EXECUTIVE, self::FINANCE]
            : [self::DEPARTMENT, self::TEAM, self::MAIN, self::EXECUTIVE, self::FINANCE];

        foreach ($order as $dashboard) {
            if ($this->allows($user, $dashboard)) {
                return $dashboard === self::MAIN ? 'dashboard' : self::DASHBOARDS[$dashboard]['route'];
            }
        }

        foreach (self::FALLBACK_ROUTES as $route) {
            if ($this->routes->allows($user, $route)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * For the role editor: what a set of permission keys (and their chosen
     * scopes) would show, without a user.
     *
     * @param  array<int, string>  $keys
     * @param  array<string, ?DataScope>  $scopes  permission key => chosen scope (null = role default)
     * @return array{visible: array<int, string>, hidden: array<int, string>, lands_on: string}
     */
    public static function summarise(array $keys, array $scopes = []): array
    {
        $visible = [];
        $hidden = [];
        $opensKeys = [];

        foreach (self::DASHBOARDS as $key => $definition) {
            $allowed = PermissionScopes::allowedScopes($definition['permission']);
            $scope = $scopes[$definition['permission']] ?? null;
            $opens = in_array($definition['permission'], $keys, true)
                && ($allowed === null || $scope === null || in_array($scope, $allowed, true));

            if ($opens) {
                $visible[] = $definition['label'];
                $opensKeys[] = $key;
            } else {
                $hidden[] = $definition['label'];
            }
        }

        // Same order as landing(): an HR overview Main Dashboard comes first.
        $mainIsOverview = in_array('view_hr_dashboard', $keys, true) && in_array('manage_employees', $keys, true);
        $order = $mainIsOverview
            ? [self::MAIN, self::DEPARTMENT, self::TEAM, self::EXECUTIVE, self::FINANCE]
            : [self::DEPARTMENT, self::TEAM, self::MAIN, self::EXECUTIVE, self::FINANCE];
        $first = collect($order)->first(fn (string $key) => in_array($key, $opensKeys, true));

        return [
            'visible' => $visible,
            'hidden' => $hidden,
            'lands_on' => $first !== null ? self::DASHBOARDS[$first]['label'] : 'My Attendance (no dashboard)',
        ];
    }
}
