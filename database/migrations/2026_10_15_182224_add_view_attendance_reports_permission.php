<?php

use App\Enums\DataScope;
use App\Models\Role;
use App\Services\Security\PermissionScopes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * QA-F-021 (decision 15 Oct): Attendance Reports get their own read-only
 * permission instead of riding on approve_leave or the broad
 * view_attendance. view_attendance_reports is scoped; it is granted to
 * exactly the roles that can open the reports today (a leave approver, or
 * a view_attendance holder whose scope reaches other people), so nobody
 * gains or loses access. Finance's company-wide reach is configured
 * explicitly on its grant. It carries no attendance-management power.
 * Additive and idempotent; per-user overrides untouched.
 */
return new class extends Migration
{
    private const KEY = 'view_attendance_reports';

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'label' => 'View Attendance Reports',
            'module' => 'Attendance',
            'description' => 'Open Attendance Reports (read-only: daily, monthly, muster, late, absent, leave and OT summaries) for the people in scope',
            'is_scoped' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');

        foreach (Role::with('permissions')->get() as $role) {
            $keys = $role->permissions->pluck('key')->all();
            $grant = $role->permissions->firstWhere('key', 'view_attendance')?->pivot;
            $attendanceScope = $grant?->scope !== null
                ? DataScope::tryFrom($grant->scope)
                : (in_array('view_attendance', $keys, true) ? PermissionScopes::defaultFor($role->slug, 'view_attendance', $keys) : null);

            $opensReports = $role->slug === 'super_admin'
                || in_array('approve_leave', $keys, true)
                || ($attendanceScope !== null && ! in_array($attendanceScope, [DataScope::Own, DataScope::None], true));

            if (! $opensReports) {
                continue;
            }

            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $role->id, 'permission_id' => $permissionId,
                // Company-wide only where explicitly configured: Finance, for payroll.
                'scope' => $role->slug === 'finance' ? DataScope::All->value : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            Cache::forget("role_{$role->id}_permission_keys");
            Cache::forget("role_{$role->id}_permission_scopes");
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
