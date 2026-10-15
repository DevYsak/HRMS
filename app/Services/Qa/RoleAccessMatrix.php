<?php

namespace App\Services\Qa;

use App\Enums\DataScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UserPermissionOverride;
use App\Services\Security\PermissionScopes;
use Illuminate\Support\Collection;

/**
 * The Role Access Matrix, read live from the roles, their permission grants
 * and the scope defaults (the same inputs ScopeResolver uses) — so it is a
 * reference of what each role can do today, not a copy that drifts.
 *
 * Cell access: allowed | scoped | explicit (only some users hold it via a
 * per-user grant) | denied. Scope: Self / Team / Department / Selected / Company.
 */
class RoleAccessMatrix
{
    /** Capability rows: label => permission key. Keys that do not exist are skipped. */
    public const CAPABILITIES = [
        'Dashboards' => [
            'Main Dashboard' => 'view_main_dashboard',
            'Team View' => 'view_team_dashboard',
            'Department View' => 'view_department_dashboard',
            'HR Dashboard' => 'view_hr_dashboard',
            'Executive Dashboard' => 'view_executive_dashboard',
        ],
        'People' => [
            'View employees' => 'view_employee',
            'Edit employees' => 'edit_employee',
            'Manage employees' => 'manage_employees',
            'Employee 360°' => 'view_employee_360',
            'Org chart' => 'view_org_chart',
        ],
        'Attendance' => [
            'Attendance records' => 'view_attendance',
            'Live attendance' => 'view_live_attendance',
            'Attendance exceptions' => 'monitor_attendance_exceptions',
            'Approve regularisation' => 'approve_regularisation',
            'HR approve regularisation' => 'hr_approve_regularisation',
        ],
        'Leave / OT / WFH' => [
            'Approve leave' => 'approve_leave',
            'Manage leave balances' => 'manage_leave_balances',
            'Approve overtime' => 'approve_overtime',
            'Approve WFH' => 'approve_wfh',
        ],
        'Payroll & Performance' => [
            'View payroll' => 'view_payroll',
            'Run payroll' => 'run_payroll',
            'Review performance' => 'review_performance',
            'Manage PIP' => 'manage_pip',
        ],
        'Reports & System' => [
            'Reports' => 'view_reports',
            'Export reports' => 'export_reports',
            'Roles & permissions' => 'manage_roles',
            'Settings' => 'manage_settings',
            'Activity log' => 'view_audit_log',
            'Login as employee' => 'impersonate',
            'QA & Audit' => 'view_qa_audit',
        ],
    ];

    /**
     * @return array{
     *     roles: Collection<int, Role>,
     *     groups: array<string, array<string, array{key: string, cells: array<string, array{access: string, scope: ?string}>}>>
     * }
     */
    public function build(): array
    {
        $roles = Role::query()->where('is_active', true)->orderByDesc('is_system')->orderBy('id')->get();
        $existing = Permission::query()->pluck('id', 'key');
        // Permissions granted to individual users, per role: role_id => [key => true].
        $explicit = UserPermissionOverride::query()
            ->where('effect', 'grant')
            ->join('users', 'users.id', '=', 'user_permission_overrides.user_id')
            ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
            ->get(['users.role_id', 'permissions.key'])
            ->groupBy('role_id')
            ->map(fn ($rows) => $rows->pluck('key')->flip());

        $groups = [];
        foreach (self::CAPABILITIES as $group => $rows) {
            foreach ($rows as $label => $key) {
                if (! $existing->has($key)) {
                    continue;
                }

                $cells = [];
                foreach ($roles as $role) {
                    $cells[$role->slug] = $this->cell($role, $key, isset($explicit[$role->id][$key]));
                }

                $groups[$group][$label] = ['key' => $key, 'cells' => $cells];
            }
        }

        return ['roles' => $roles, 'groups' => $groups];
    }

    /** @return array{access: string, scope: ?string} */
    private function cell(Role $role, string $key, bool $explicitlyGranted): array
    {
        if ($role->slug === 'super_admin') {
            return ['access' => 'allowed', 'scope' => 'Company'];
        }

        $keys = $role->permissionKeys();
        if (! in_array($key, $keys, true)) {
            return ['access' => $explicitlyGranted ? 'explicit' : 'denied', 'scope' => null];
        }

        if (! PermissionScopes::isScoped($key)) {
            return ['access' => 'allowed', 'scope' => null];
        }

        $grant = $role->permissionScopes()[$key] ?? null;
        $scope = $grant !== null && $grant['scope'] !== null
            ? (DataScope::tryFrom($grant['scope']) ?? DataScope::Team)
            : PermissionScopes::defaultFor($role->slug, $key, $keys);

        return [
            'access' => $scope === DataScope::All ? 'allowed' : ($scope === DataScope::None ? 'denied' : 'scoped'),
            'scope' => self::scopeLabel($scope),
        ];
    }

    public static function scopeLabel(DataScope $scope): ?string
    {
        return match ($scope) {
            DataScope::Own => 'Self',
            DataScope::Team => 'Team',
            DataScope::Department => 'Department',
            DataScope::SelectedDepartments => 'Selected',
            DataScope::All => 'Company',
            DataScope::None => null,
        };
    }
}
