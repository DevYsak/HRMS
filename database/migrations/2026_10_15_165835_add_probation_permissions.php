<?php

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * QA-F-014: probation was authorised by role NAME (manager / director /
 * super_admin for step 1, hr_admin / super_admin for step 2). Three explicit,
 * scoped permissions replace that:
 *  - view_probation        open the probation page (whoever opened it before:
 *                          approve-leave or manage-employees holders);
 *  - confirm_probation     step 1, the line confirmation (roles that pass the
 *                          old manager / director / super_admin check);
 *  - hr_approve_probation  step 2, HR co-approval (hr_admin / super_admin).
 * Granted to exactly the roles that had each ability, so nobody gains or
 * loses access. Per-user overrides are untouched. Idempotent.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    private const KEYS = [
        'view_probation' => ['View Probation', 'Open the probation confirmation page of employees in scope'],
        'confirm_probation' => ['Confirm Probation', 'Step 1: confirm an employee\'s probation as their line approver'],
        'hr_approve_probation' => ['HR-Approve Probation', 'Step 2: co-approve a confirmed probation and move the employee to Confirmed'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::KEYS as $key => [$label, $description]) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, 'label' => $label, 'module' => 'Employee Management', 'description' => $description,
                'is_scoped' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::KEYS))->pluck('id', 'key');

        foreach (Role::with('permissions')->get() as $role) {
            $keys = $role->permissions->pluck('key')->all();
            $bucket = $role->legacyBucket()->value;
            $grant = array_filter([
                'view_probation' => $role->slug === 'super_admin' || in_array('approve_leave', $keys, true) || in_array('manage_employees', $keys, true),
                'confirm_probation' => in_array($bucket, [UserRole::Manager->value, UserRole::Director->value, UserRole::SuperAdmin->value], true),
                'hr_approve_probation' => in_array($bucket, [UserRole::HrAdmin->value, UserRole::SuperAdmin->value], true),
            ]);

            foreach (array_keys($grant) as $key) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $ids[$key], 'created_at' => $now, 'updated_at' => $now]);
            }
            Cache::forget("role_{$role->id}_permission_keys");
            Cache::forget("role_{$role->id}_permission_scopes");
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::KEYS))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permission_overrides')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('roles')->pluck('id')->each(function ($id) {
            Cache::forget("role_{$id}_permission_keys");
            Cache::forget("role_{$id}_permission_scopes");
        });
    }
};
