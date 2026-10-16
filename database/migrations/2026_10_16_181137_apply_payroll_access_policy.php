<?php

use App\Enums\DataScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 payroll access policy (approved 16 Oct 2026). Permission = what
 * action, scope = whose payroll; never inferred from role names.
 *
 *  - export_bank_transfer (new, privileged): the bank-transfer file. Finance
 *    by default (Super Admin holds everything).
 *  - view_department_payroll_summary (new, scoped): aggregate department
 *    payroll cost, never individual pay. No role by default — explicit grant.
 *  - approve_payroll for HR Admin: payroll approval steps are now decided by
 *    permission; an "HR review" step used to accept the hr_admin role name.
 *  - Directors lose the default view_payroll grant: no employee
 *    compensation unless explicitly granted (re-grant in Roles & Permissions).
 *
 * Idempotent; per-user overrides untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore([
            ['key' => 'export_bank_transfer', 'label' => 'Export Bank Transfer File', 'module' => 'Payroll',
                'description' => 'Download the bank-transfer file (account numbers and net pay). Company-wide payroll access is also required',
                'is_scoped' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'view_department_payroll_summary', 'label' => 'View Department Payroll Summary', 'module' => 'Payroll',
                'description' => 'See aggregate payroll cost for the departments in scope — no individual salary, bank details or payslips. Explicit grant only',
                'is_scoped' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $permission = fn (string $key) => DB::table('permissions')->where('key', $key)->value('id');
        $role = fn (string $slug) => DB::table('roles')->where('slug', $slug)->value('id');
        $touched = [];

        $grant = function (string $slug, string $key, ?DataScope $scope = null) use ($permission, $role, $now, &$touched) {
            $roleId = $role($slug);
            if ($roleId === null) {
                return;
            }
            DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permission($key),
                'scope' => $scope?->value, 'created_at' => $now, 'updated_at' => $now]);
            $touched[] = $roleId;
        };

        $grant('finance', 'export_bank_transfer');
        $grant('hr_admin', 'approve_payroll');

        if (($directorId = $role('director')) !== null && ($viewPayroll = $permission('view_payroll')) !== null) {
            DB::table('role_permission')->where('role_id', $directorId)->where('permission_id', $viewPayroll)->delete();
            $touched[] = $directorId;
        }

        foreach (array_unique($touched) as $roleId) {
            Cache::forget("role_{$roleId}_permission_keys");
            Cache::forget("role_{$roleId}_permission_scopes");
        }
    }

    public function down(): void
    {
        // Access policy: not reverted automatically (re-grant in Roles & Permissions).
    }
};
