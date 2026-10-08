<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * D1 (8 Oct 2026): a Director works inside their own / headed department and
 * does not create, delete or offboard employees, or sign off payroll, unless
 * an admin grants it in Roles & Permissions.
 *
 * Creating, deleting and offboarding employees used to be decided by
 * manage_employees alone, so their own keys were never checked. Those checks
 * are now real, so first every other role that manages employees is given
 * the keys it was effectively using — nobody but the Director loses access.
 *
 * Then the Director role loses create_employee, delete_employee,
 * manage_offboarding, approve_payroll and approve_finance. approve_finance
 * is kept when the active payroll approval chain has a Director step, so a
 * run in flight is never left with an approver who cannot open it; that is
 * logged for an admin to resolve.
 *
 * Matched by permission key and role slug, never by id. Safe to run more
 * than once.
 */
return new class extends Migration
{
    /** @var array<string, array{label: string, description: string, module: string}> */
    private const EMPLOYEE_KEYS = [
        'create_employee' => ['label' => 'Create Employee', 'description' => 'Add new employee profiles', 'module' => 'Employee Management'],
        'delete_employee' => ['label' => 'Delete Employee', 'description' => 'Remove employee records', 'module' => 'Employee Management'],
        'manage_onboarding' => ['label' => 'Manage Onboarding', 'description' => 'Run new-hire onboarding workflows', 'module' => 'Employee Management'],
        'manage_offboarding' => ['label' => 'Manage Offboarding', 'description' => 'Run employee offboarding workflows', 'module' => 'Employee Management'],
    ];

    /** @var array<int, string> */
    private const DIRECTOR_REVOKE = ['create_employee', 'delete_employee', 'manage_offboarding', 'approve_payroll', 'approve_finance'];

    public function up(): void
    {
        $now = now();

        foreach (self::EMPLOYEE_KEYS as $key => $def) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, ...$def, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $manageEmployees = DB::table('permissions')->where('key', 'manage_employees')->value('id');
        $employeeKeyIds = DB::table('permissions')->whereIn('key', array_keys(self::EMPLOYEE_KEYS))->pluck('id');

        $managingRoles = $manageEmployees === null ? collect() : DB::table('role_permission')
            ->join('roles', 'roles.id', '=', 'role_permission.role_id')
            ->where('role_permission.permission_id', $manageEmployees)
            ->where('roles.slug', '!=', 'director')
            ->pluck('role_permission.role_id');

        foreach ($managingRoles as $roleId) {
            DB::table('role_permission')->insertOrIgnore($employeeKeyIds->map(fn ($id) => [
                'role_id' => $roleId, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        if ($director = DB::table('roles')->where('slug', 'director')->value('id')) {
            $revoke = self::DIRECTOR_REVOKE;

            if ($this->chainHasActiveDirectorStep()) {
                $revoke = array_values(array_diff($revoke, ['approve_finance']));
                Log::warning('D1: Director kept approve_finance because the payroll approval chain has an active Director step. Remove the step or confirm the grant in Roles & Permissions.');
            }

            $revokeIds = DB::table('permissions')->whereIn('key', $revoke)->pluck('id');
            DB::table('role_permission')->where('role_id', $director)->whereIn('permission_id', $revokeIds)->delete();
        }

        DB::table('roles')->pluck('id')->each(function ($id) {
            Cache::forget("role_{$id}_permission_keys");
            Cache::forget("role_{$id}_permission_scopes");
        });
    }

    /**
     * Deliberately a no-op: the Director grants were defaults, not choices
     * this migration can tell apart from an admin's, and the keys given to
     * other roles only spell out access they already had.
     */
    public function down(): void
    {
        //
    }

    private function chainHasActiveDirectorStep(): bool
    {
        return DB::getSchemaBuilder()->hasTable('payroll_approval_policies')
            && DB::table('payroll_approval_policies')->where('is_active', true)->where('approver_type', 'director')->exists();
    }
};
