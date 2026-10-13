<?php

namespace App\Services\Security;

use App\Models\Permission;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * How the role editor groups permissions for people (no data change): nine
 * sections in a fixed order, from each permission's stored module, with a few
 * keys moved to where an admin looks for them. Also the presets offered as an
 * admin convenience — the same default grants the seeder installs.
 */
class PermissionSections
{
    public const ORDER = ['Dashboard Access', 'People', 'Attendance', 'Leave', 'Overtime', 'Performance', 'Payroll', 'Reports', 'Settings'];

    /** stored module => section */
    private const MODULES = [
        'Dashboard' => 'Dashboard Access',
        'Employee Management' => 'People',
        'Profile' => 'People',
        'Documents' => 'People',
        'Attendance' => 'Attendance',
        'Leave Management' => 'Leave',
        'Overtime' => 'Overtime',
        'Performance' => 'Performance',
        'Payroll' => 'Payroll',
        'Reports' => 'Reports',
        'Settings' => 'Settings',
        'Notifications' => 'Settings',
    ];

    /** permission key => section, where the module alone would mislead */
    private const KEYS = [
        'approve_overtime' => 'Overtime',
        'view_audit_log' => 'Reports',
        'data_export' => 'Reports',
    ];

    /** The presets offered in the role editor: role slug => label. */
    public const PRESETS = [
        'employee' => 'Employee',
        'coordinator' => 'Coordinator',
        'manager' => 'Manager',
        'department_head' => 'Department Head',
        'hr_admin' => 'HR Admin',
        'finance' => 'Finance',
    ];

    public static function for(Permission $permission): string
    {
        return self::KEYS[$permission->key] ?? self::MODULES[$permission->module] ?? 'Settings';
    }

    /**
     * The default grant set for a preset — the seeder's role defaults plus
     * the permissions added since (PermissionScopes::NEW_ROLE_GRANTS).
     *
     * @return array<int, string>
     */
    public static function presetKeys(string $slug): array
    {
        return array_values(array_unique(array_merge(
            RolesAndPermissionsSeeder::ROLE_DEFAULTS[$slug] ?? [],
            PermissionScopes::NEW_ROLE_GRANTS[$slug] ?? [],
        )));
    }
}
