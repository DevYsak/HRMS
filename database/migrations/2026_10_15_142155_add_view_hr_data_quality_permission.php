<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * People → Data Quality (read-only org structure & data-quality audit).
 * Granted to Super Admin and HR Admin; scoped, so a department-scoped HR
 * admin sees only their departments. Additive and idempotent.
 */
return new class extends Migration
{
    private const KEY = 'view_hr_data_quality';

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'label' => 'View Data Quality',
            'module' => 'Employee Management',
            'description' => 'See the read-only org structure & data-quality audit (missing master data, reporting lines, role drift)',
            'is_scoped' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
        foreach (DB::table('roles')->whereIn('slug', ['super_admin', 'hr_admin'])->pluck('id') as $roleId) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            Cache::forget("role_{$roleId}_permission_keys");
            Cache::forget("role_{$roleId}_permission_scopes");
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', self::KEY)->value('id');
        if ($id === null) {
            return;
        }
        DB::table('role_permission')->where('permission_id', $id)->delete();
        DB::table('user_permission_overrides')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        DB::table('roles')->pluck('id')->each(fn ($roleId) => Cache::forget("role_{$roleId}_permission_keys"));
    }
};
