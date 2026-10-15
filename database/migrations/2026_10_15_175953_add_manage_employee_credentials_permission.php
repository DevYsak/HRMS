<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * QA-F-030: login credentials get their own permission. Editing an employee
 * (manage_employees / edit_employee, at any scope) no longer implies control
 * of their account. manage_employee_credentials — send a reset link, set a
 * temporary password, force a reset, issue a login invitation — is granted
 * to Super Admin and HR Admin only, and is privileged (only a Super Admin
 * can hand it on). Additive and idempotent; per-user overrides untouched.
 */
return new class extends Migration
{
    private const KEY = 'manage_employee_credentials';

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'label' => 'Manage Employee Credentials',
            'module' => 'Employee Management',
            'description' => 'Send password reset links, set temporary passwords, force a password reset and issue login invitations',
            'is_scoped' => false,
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
