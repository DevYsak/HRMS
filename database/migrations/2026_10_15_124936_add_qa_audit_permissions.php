<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * QA & Audit permissions — Super Admin only by default. An HR Admin may be
 * granted view_qa_audit (read-only) in Roles & Permissions; the keys are
 * privileged, so only a Super Admin can hand them on. Additive and
 * idempotent.
 */
return new class extends Migration
{
    private const MODULE = 'QA & Audit';

    /** @var array<string, array{0: string, 1: string}> */
    private const KEYS = [
        'view_qa_audit' => ['View QA & Audit', 'Open the QA & Audit tracker: test cases, findings, fixes, readiness (read-only)'],
        'manage_qa_audit' => ['Manage QA & Audit', 'Create and edit test cases and findings, record results, link fixes and verify retests'],
        'run_qa_checks' => ['Record QA Runs', 'Record and import automated test runs (Pest / PHPUnit / Playwright summaries)'],
        'export_qa_report' => ['Export QA Report', 'Generate the QA, Audit & Readiness report and export QA data'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::KEYS as $key => [$label, $description]) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, 'label' => $label, 'module' => self::MODULE, 'description' => $description,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $superAdmin = DB::table('roles')->where('slug', 'super_admin')->value('id');
        if ($superAdmin !== null) {
            DB::table('role_permission')->insertOrIgnore(
                DB::table('permissions')->whereIn('key', array_keys(self::KEYS))->pluck('id')
                    ->map(fn ($id) => ['role_id' => $superAdmin, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now])
                    ->all()
            );
            Cache::forget("role_{$superAdmin}_permission_keys");
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::KEYS))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permission_overrides')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('roles')->pluck('id')->each(fn ($id) => Cache::forget("role_{$id}_permission_keys"));
    }
};
