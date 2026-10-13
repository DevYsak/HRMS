<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Access: one explicit permission per dashboard, so Roles &
 * Permissions decides which dashboards a role sees (sidebar, URL and landing).
 *
 *   view_main_dashboard        Main Dashboard (replaces the unused view_dashboard)
 *   view_team_dashboard        Team View        (scoped: team / department / company)
 *   view_department_dashboard  Department View  (scoped: department / company)
 *   view_finance_dashboard     Finance View
 *   view_executive_dashboard   Executive View   (existing; relabelled)
 *
 * Grants keep what each role sees today, except where the new rule differs:
 *   - Team View: every role that approves leave (as before).
 *   - Department View: Director only — a Department Head gets it when an
 *     admin grants it (it repeated Team View's people).
 *   - Finance View: roles that run payroll or sign off finance (as before).
 *   - Main Dashboard: roles that had view_dashboard, except Department Head,
 *     Manager, Director and Finance, whose own view is their dashboard.
 * User overrides of view_dashboard move to view_main_dashboard. Idempotent.
 */
return new class extends Migration
{
    private const MODULE = 'Dashboard';

    private const NEW = [
        'view_main_dashboard' => ['Main Dashboard', 'Open the main dashboard: the company or HR overview, or the employee’s own self-service dashboard', false],
        'view_team_dashboard' => ['Team View', 'Open Team View (approvals and today’s attendance for the people they approve). Scope: the level it opens at — team, department or company', true],
        'view_department_dashboard' => ['Department View', 'Open Department View (a department’s attendance, leave and approvals). Scope: department or company', true],
        'view_finance_dashboard' => ['Finance View', 'Open Finance View (payroll queue, overtime payable, finance sign-off)', false],
    ];

    private const NO_MAIN = ['department_head', 'manager', 'director', 'finance'];

    public function up(): void
    {
        $now = now();

        foreach (self::NEW as $key => [$label, $description, $scoped]) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, 'label' => $label, 'module' => self::MODULE, 'description' => $description,
                'is_scoped' => $scoped, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('permissions')->where('key', 'view_executive_dashboard')->update([
            'label' => 'Executive View', 'description' => 'Open Executive View (company-wide insights; needs company-wide approvals)', 'updated_at' => $now,
        ]);
        DB::table('permissions')->where('key', 'view_hr_dashboard')->update([
            'label' => 'HR Overview widgets', 'description' => 'Show the HR overview on the Main Dashboard (with Manage Employees)', 'updated_at' => $now,
        ]);

        $id = fn (string $key) => DB::table('permissions')->where('key', $key)->value('id');
        $rolesHolding = fn (array $keys) => DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->whereIn('permissions.key', $keys)
            ->distinct()
            ->pluck('role_permission.role_id')
            ->all();
        $roleIdsFor = fn (array $slugs) => DB::table('roles')->whereIn('slug', $slugs)->pluck('id')->all();

        $grants = [
            'view_team_dashboard' => $rolesHolding(['approve_leave']),
            'view_department_dashboard' => $roleIdsFor(['director']),
            'view_finance_dashboard' => $rolesHolding(['run_payroll', 'approve_finance']),
            'view_main_dashboard' => array_values(array_diff($rolesHolding(['view_dashboard']), $roleIdsFor(self::NO_MAIN))),
        ];

        foreach ($grants as $key => $roleIds) {
            $permissionId = $id($key);
            DB::table('role_permission')->insertOrIgnore(array_map(fn ($roleId) => [
                'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
            ], $roleIds));
        }

        // view_dashboard is retired: its user overrides become Main Dashboard overrides.
        if ($old = $id('view_dashboard')) {
            $main = $id('view_main_dashboard');
            $users = DB::table('user_permission_overrides')->where('permission_id', $old)->pluck('user_id')->all();
            foreach (DB::table('user_permission_overrides')->where('permission_id', $old)->get() as $override) {
                $exists = DB::table('user_permission_overrides')->where('user_id', $override->user_id)->where('permission_id', $main)->exists();
                if (! $exists) {
                    DB::table('user_permission_overrides')->where('id', $override->id)->update(['permission_id' => $main]);
                }
            }
            DB::table('user_permission_overrides')->where('permission_id', $old)->delete();
            DB::table('role_permission')->where('permission_id', $old)->delete();
            DB::table('permissions')->where('id', $old)->delete();

            foreach ($users as $userId) {
                Cache::forget("user_{$userId}_permission_overrides");
            }
        }

        $this->flushRoleCaches();
    }

    public function down(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'key' => 'view_dashboard', 'label' => 'View Dashboard', 'module' => self::MODULE,
            'description' => 'Access the personal dashboard', 'is_scoped' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $old = DB::table('permissions')->where('key', 'view_dashboard')->value('id');
        DB::table('role_permission')->insertOrIgnore(DB::table('roles')->pluck('id')->map(fn ($roleId) => [
            'role_id' => $roleId, 'permission_id' => $old, 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        $ids = DB::table('permissions')->whereIn('key', array_keys(self::NEW))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permission_overrides')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('permissions')->where('key', 'view_executive_dashboard')->update(['label' => 'View Executive Dashboard', 'description' => 'Access company-wide executive insights']);
        DB::table('permissions')->where('key', 'view_hr_dashboard')->update(['label' => 'View HR Dashboard', 'description' => 'Access HR overview metrics and widgets']);

        $this->flushRoleCaches();
        DB::table('users')->pluck('id')->each(fn ($id) => Cache::forget("user_{$id}_permission_overrides"));
    }

    private function flushRoleCaches(): void
    {
        DB::table('roles')->pluck('id')->each(function ($id) {
            Cache::forget("role_{$id}_permission_keys");
            Cache::forget("role_{$id}_permission_scopes");
        });
    }
};
