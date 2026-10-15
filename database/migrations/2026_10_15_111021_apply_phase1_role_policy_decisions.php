<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Role policy decisions of 15 Oct 2026 (Phase 1 audit), applied to the
 * built-in roles of an existing database:
 *
 *  - Department Head: Department View on (scoped to the headed department by
 *    the role default) next to Team View.
 *  - Coordinator: Team View on (department scope by default); the Main
 *    dashboard only when granted explicitly — the role-level grant goes.
 *  - Finance: performance management (review_performance, manage_pip,
 *    manage_promotions) is no longer a role default.
 *
 * Only the built-in role rows change. Per-user overrides (an explicit grant
 * to one person) and custom roles are never touched, so anyone given these
 * permissions on purpose keeps them. Idempotent and reversible.
 */
return new class extends Migration
{
    /** @var array<string, array{grant: array<int, string>, revoke: array<int, string>}> */
    private const CHANGES = [
        'department_head' => ['grant' => ['view_department_dashboard'], 'revoke' => []],
        'coordinator' => ['grant' => ['view_team_dashboard'], 'revoke' => ['view_main_dashboard']],
        'finance' => ['grant' => [], 'revoke' => ['review_performance', 'manage_pip', 'manage_promotions']],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as $slug => $change) {
            $this->apply($slug, $change['grant'], $change['revoke']);
        }
    }

    public function down(): void
    {
        foreach (self::CHANGES as $slug => $change) {
            $this->apply($slug, $change['revoke'], $change['grant']);
        }
    }

    /**
     * @param  array<int, string>  $grant
     * @param  array<int, string>  $revoke
     */
    private function apply(string $slug, array $grant, array $revoke): void
    {
        $roleId = DB::table('roles')->where('slug', $slug)->value('id');
        if ($roleId === null) {
            return;
        }

        $now = now();
        $ids = fn (array $keys) => DB::table('permissions')->whereIn('key', $keys)->pluck('id')->all();

        if ($grant !== []) {
            DB::table('role_permission')->insertOrIgnore(array_map(fn ($permissionId) => [
                'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
            ], $ids($grant)));
        }

        if ($revoke !== []) {
            DB::table('role_permission')->where('role_id', $roleId)->whereIn('permission_id', $ids($revoke))->delete();
        }

        Cache::forget("role_{$roleId}_permission_keys");
        Cache::forget("role_{$roleId}_permission_scopes");
    }
};
