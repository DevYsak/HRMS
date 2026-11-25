<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Notify Others (25 Nov 2026): who may choose extra people to tell about a
 * leave application (the employee) or a decision (HR's "Also notify").
 * Granted to every role that can apply for or decide on leave today, so
 * nobody loses HR's existing "Also notify" and employees gain the choice;
 * Admin takes it away per role in Roles & Permissions. Unscoped. Additive
 * and idempotent; per-user overrides untouched.
 */
return new class extends Migration
{
    private const KEY = 'notify_others_on_leave';

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'label' => 'Notify Others on Leave',
            'module' => 'Leave Management',
            'description' => 'Choose extra people to tell about a leave application or decision (the manager, approvers and HR are always told)',
            'is_scoped' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');

        $leaveIds = DB::table('permissions')->whereIn('key', ['apply_leave', 'approve_leave'])->pluck('id');
        $roleIds = DB::table('role_permission')->whereIn('permission_id', $leaveIds)->distinct()->pluck('role_id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $roleId, 'permission_id' => $permissionId, 'scope' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
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
        DB::table('roles')->pluck('id')->each(function ($roleId) {
            Cache::forget("role_{$roleId}_permission_keys");
            Cache::forget("role_{$roleId}_permission_scopes");
        });
    }
};
