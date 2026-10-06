<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HR controls that used to be hard-wired to the Super Admin become
 * permissions, granted to the HR Admin role by default:
 *
 *   manage_ai_settings     AI Assistant provider, key and access
 *   data_purge             Data Management: bulk-clear operational data
 *   force_delete_employee  Permanently delete an archived employee
 *   impersonate            Login as an employee
 *
 * They stay on RoleDelegationGuard::PRIVILEGED, so only a Super Admin can
 * hand them to any other role. Idempotent; down() removes them.
 */
return new class extends Migration
{
    /** @var array<string, array{label: string, description: string, module: string}> */
    private const PERMISSIONS = [
        'manage_ai_settings' => ['label' => 'Manage AI Assistant', 'description' => 'Configure the AI provider, API key and which roles may use the assistant', 'module' => 'Settings'],
        'data_purge' => ['label' => 'Data Management (Purge)', 'description' => 'Permanently clear operational data and delete employees from Data Management', 'module' => 'Settings'],
        'force_delete_employee' => ['label' => 'Permanently Delete Employees', 'description' => 'Erase an archived employee and all their records for good', 'module' => 'Employee Management'],
        'impersonate' => ['label' => 'Login as Employee', 'description' => 'View the system as another user to support or test their access', 'module' => 'Settings'],
    ];

    public function up(): void
    {
        $now = now();
        $hr = DB::table('roles')->where('slug', 'hr_admin')->value('id');

        foreach (self::PERMISSIONS as $key => $def) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, ...$def, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $permission = DB::table('permissions')->where('key', $key)->value('id');

            if ($permission !== null && $hr !== null) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $hr, 'permission_id' => $permission, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if ($hr !== null) {
            Cache::forget("role_{$hr}_permission_keys");
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        foreach (DB::table('roles')->pluck('id') as $role) {
            Cache::forget("role_{$role}_permission_keys");
        }
    }
};
