<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Brings the HR Admin role's day-to-day permissions into line on every
 * database, so production matches local after a plain `migrate`:
 *
 *   Settings     manage_settings, manage_company_settings
 *   Employees    the employee-management keys the seeder intends for HR
 *                (manage_employees, view_employee, view_directory, ...)
 *   Support      impersonate (Login as / "View as this employee")
 *
 * Destructive controls stay Super Admin-only: data_purge and
 * force_delete_employee are taken off HR Admin (granted by the
 * 2026_10_06_130550 migration). manage_roles is left exactly as it is.
 *
 * Everything is matched by permission key and role slug — never by id —
 * so it is correct whatever ids a database assigned. Missing permission
 * rows are created, existing rows and grants are left alone, and no other
 * role or HR permission is touched. Safe to run more than once.
 */
return new class extends Migration
{
    private const ROLE = 'hr_admin';

    /** @var array<string, array{label: string, description: string, module: string}> */
    private const GRANT = [
        'manage_settings' => ['label' => 'Manage Settings', 'description' => 'Configure company-wide system settings', 'module' => 'Settings'],
        'manage_company_settings' => ['label' => 'Manage Company Settings', 'description' => 'Configure company profile, branding and policies', 'module' => 'Settings'],
        'impersonate' => ['label' => 'Login as Employee', 'description' => 'View the system as another user to support or test their access', 'module' => 'Settings'],
        'manage_employees' => ['label' => 'Manage Employees', 'description' => 'Full access to employee records and lifecycle', 'module' => 'Employee Management'],
        'create_employee' => ['label' => 'Create Employee', 'description' => 'Add new employee profiles', 'module' => 'Employee Management'],
        'edit_employee' => ['label' => 'Edit Employee', 'description' => 'Update existing employee profiles', 'module' => 'Employee Management'],
        'delete_employee' => ['label' => 'Delete Employee', 'description' => 'Remove employee records', 'module' => 'Employee Management'],
        'view_employee' => ['label' => 'View Employee', 'description' => 'View individual employee profiles', 'module' => 'Employee Management'],
        'view_directory' => ['label' => 'View Directory', 'description' => 'Browse the company employee directory', 'module' => 'Employee Management'],
        'view_org_chart' => ['label' => 'View Org Chart', 'description' => 'View the organisational hierarchy', 'module' => 'Employee Management'],
        'manage_onboarding' => ['label' => 'Manage Onboarding', 'description' => 'Run new-hire onboarding workflows', 'module' => 'Employee Management'],
        'manage_offboarding' => ['label' => 'Manage Offboarding', 'description' => 'Run employee offboarding workflows', 'module' => 'Employee Management'],
    ];

    /** @var array<int, string> */
    private const SUPER_ADMIN_ONLY = ['data_purge', 'force_delete_employee'];

    public function up(): void
    {
        $hr = DB::table('roles')->where('slug', self::ROLE)->value('id');

        if ($hr === null) {
            return;
        }

        $now = now();

        foreach (self::GRANT as $key => $def) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, ...$def, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $grantIds = DB::table('permissions')->whereIn('key', array_keys(self::GRANT))->pluck('id');

        DB::table('role_permission')->insertOrIgnore($grantIds->map(fn ($id) => [
            'role_id' => $hr, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        $revokeIds = DB::table('permissions')->whereIn('key', self::SUPER_ADMIN_ONLY)->pluck('id');

        DB::table('role_permission')->where('role_id', $hr)->whereIn('permission_id', $revokeIds)->delete();

        Cache::forget("role_{$hr}_permission_keys");
    }

    /**
     * Deliberately a no-op: the grants may have existed before this ran, so
     * removing them would take access away that this migration never gave.
     */
    public function down(): void
    {
        //
    }
};
