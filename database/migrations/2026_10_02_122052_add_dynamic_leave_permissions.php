<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Permissions for HR Leave Management (Phase 2D).
 *
 * Inserted here, not only in RolesAndPermissionsSeeder, so an existing
 * database gets them without re-seeding (Gate::before resolves an ability
 * only once its permissions row exists). Granted to the HR Admin role by
 * default; Super Admin holds every permission implicitly. Idempotent.
 */
return new class extends Migration
{
    /** @var array<int, array{key: string, label: string, description: string}> */
    private const PERMISSIONS = [
        ['key' => 'view_leave_management', 'label' => 'View Leave Management', 'description' => 'Open HR Leave Management and every employee\'s leave balances and history'],
        ['key' => 'add_leave_balance', 'label' => 'Add Leave', 'description' => 'Grant add-on leave or credit an employee\'s balance'],
        ['key' => 'deduct_leave_balance', 'label' => 'Deduct Leave', 'description' => 'Debit an employee\'s leave balance'],
        ['key' => 'correct_leave_balance', 'label' => 'Correct Leave Balance', 'description' => 'Set a balance to a stated figure (posted as an adjustment)'],
        ['key' => 'apply_leave_on_behalf', 'label' => 'Apply Leave on Behalf', 'description' => 'Submit a leave request for another employee'],
        ['key' => 'override_leave_policy', 'label' => 'Employee Leave Override', 'description' => 'Create or revoke an employee-specific entitlement override'],
        ['key' => 'bulk_allocate_leave', 'label' => 'Bulk Leave Operations', 'description' => 'Bulk provision missing balances and bulk add-on leave'],
        ['key' => 'run_leave_rollover', 'label' => 'Run Leave Rollover', 'description' => 'Preview and process the year-end leave rollover'],
        ['key' => 'reconcile_leave', 'label' => 'Reconcile Leave', 'description' => 'Run leave reconciliation and apply safe fixes'],
        ['key' => 'export_leave', 'label' => 'Export Leave', 'description' => 'Export leave balances, rollover and reconciliation reports'],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore(array_map(fn (array $p) => $p + [
            'module' => 'Leave Management', 'created_at' => $now, 'updated_at' => $now,
        ], self::PERMISSIONS));

        $hr = DB::table('roles')->where('slug', 'hr_admin')->value('id');

        if ($hr !== null) {
            $ids = DB::table('permissions')->whereIn('key', array_column(self::PERMISSIONS, 'key'))->pluck('id');

            DB::table('role_permission')->insertOrIgnore($ids->map(fn ($id) => [
                'role_id' => $hr, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now,
            ])->all());

            Cache::forget("role_{$hr}_permission_keys");
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_column(self::PERMISSIONS, 'key'))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
