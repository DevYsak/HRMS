<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "View Activity Log" — read-only access to the central audit trail, granted
 * to the HR Admin role by default (Super Admin holds every permission).
 * Manage Settings keeps granting it too, so no one loses access.
 * Idempotent; down() removes it.
 */
return new class extends Migration
{
    private const KEY = 'view_audit_log';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'label' => 'View Activity Log',
            'description' => 'Read the central activity log / audit trail and export it',
            'module' => 'Settings',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permission = DB::table('permissions')->where('key', self::KEY)->value('id');
        $hr = DB::table('roles')->where('slug', 'hr_admin')->value('id');

        if ($permission !== null && $hr !== null) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $hr, 'permission_id' => $permission, 'created_at' => $now, 'updated_at' => $now,
            ]);
            Cache::forget("role_{$hr}_permission_keys");
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', self::KEY)->value('id');

        if ($id !== null) {
            DB::table('role_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
