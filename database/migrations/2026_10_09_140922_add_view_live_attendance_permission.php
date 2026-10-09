<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * view_live_attendance — opens the Live Attendance panel. Scoped: the scope
 * decides whose attendance it shows (own / team / department / selected
 * departments / company). Granted to the built-in roles that watch
 * attendance; each keeps its role default scope (HR Admin and Super Admin:
 * company; Department Head, Director and Coordinator: department; Manager:
 * team) until an admin changes it in Roles & Permissions. Additive and
 * idempotent: an existing grant or a custom role is never changed.
 */
return new class extends Migration
{
    private const KEY = 'view_live_attendance';

    private const ROLES = ['super_admin', 'hr_admin', 'director', 'department_head', 'manager', 'coordinator'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'label' => 'View Live Attendance',
            'module' => 'Attendance',
            'description' => "Open the Live Attendance panel: today's punches and status for employees in scope",
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
