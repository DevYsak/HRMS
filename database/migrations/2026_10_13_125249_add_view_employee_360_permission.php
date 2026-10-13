<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * view_employee_360 — opens the Employee 360° page. Scoped: the scope
 * decides whose snapshot it opens (own / team / department / selected
 * departments / company). Granted to the built-in roles that look after
 * people; each keeps its role default scope (HR Admin and Super Admin:
 * company; Department Head and Director: department; Manager: team).
 * Coordinators, Finance and employees get it only when granted in Roles & Permissions. Additive and
 * idempotent: an existing grant or a custom role is never changed.
 */
return new class extends Migration
{
    private const KEY = 'view_employee_360';

    private const ROLES = ['super_admin', 'hr_admin', 'director', 'department_head', 'manager'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'label' => 'View Employee 360°',
            'module' => 'Employee Management',
            'description' => 'Open the Employee 360° page: one snapshot of attendance, leave, overtime, performance and activity for employees in scope',
            'is_scoped' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
        $roleIds = DB::table('roles')->whereIn('slug', self::ROLES)->pluck('id');

        DB::table('role_permission')->insertOrIgnore($roleIds->map(fn ($roleId) => [
            'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        $roleIds->each(function ($id) {
            Cache::forget("role_{$id}_permission_keys");
            Cache::forget("role_{$id}_permission_scopes");
        });
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('role_permission')->where('permission_id', $permissionId)->delete();
        DB::table('user_permission_overrides')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
        DB::table('roles')->pluck('id')->each(function ($id) {
            Cache::forget("role_{$id}_permission_keys");
            Cache::forget("role_{$id}_permission_scopes");
        });
    }
};
