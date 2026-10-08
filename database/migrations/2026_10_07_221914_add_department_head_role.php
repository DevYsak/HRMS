<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Department Head system role. Its permissions reach the departments
 * the holder works in or heads (Settings → Departments → Head) by default;
 * Roles & Permissions can change that per permission.
 *
 * Nobody is moved onto it: existing department heads keep their current
 * role (and their reporting-line reach over headed departments) until HR
 * assigns it. Idempotent; an existing role's permissions are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->where('slug', 'department_head')->exists()) {
            return;
        }

        $now = now();

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Department Head',
            'slug' => 'department_head',
            'description' => 'Runs a department: attendance, leave approvals, performance, notifications and reports for the departments they head.',
            'is_system' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_permission')->insertOrIgnore(
            DB::table('permissions')->whereIn('key', RolesAndPermissionsSeeder::DEPARTMENT_HEAD_PERMISSIONS)->pluck('id')
                ->map(fn ($id) => ['role_id' => $roleId, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now])
                ->all(),
        );
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'department_head')->value('id');

        if ($roleId === null || DB::table('users')->where('role_id', $roleId)->exists()) {
            return;
        }

        DB::table('role_permission')->where('role_id', $roleId)->delete();
        DB::table('roles')->where('id', $roleId)->delete();
    }
};
