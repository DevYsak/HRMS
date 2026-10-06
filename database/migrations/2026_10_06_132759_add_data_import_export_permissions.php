<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Import / Export centre's two permissions, granted to the HR Admin role
 * by default (Super Admin holds every permission implicitly):
 *
 *   data_export  download employees, leave, attendance and holidays
 *   data_import  bulk-load data, starting with holidays, after a preview
 *
 * Idempotent; down() removes them.
 */
return new class extends Migration
{
    /** @var array<string, array{label: string, description: string, module: string}> */
    private const PERMISSIONS = [
        'data_export' => ['label' => 'Export Data', 'description' => 'Download employees, leave, attendance and holiday data from the Import / Export centre', 'module' => 'Settings'],
        'data_import' => ['label' => 'Import Data', 'description' => 'Bulk-load data such as holidays from a spreadsheet, after a validated preview', 'module' => 'Settings'],
    ];

    public function up(): void
    {
        $now = now();
        $hr = DB::table('roles')->where('slug', 'hr_admin')->value('id');

        foreach (self::PERMISSIONS as $key => $def) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, ...$def, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $permission = DB::table('permissions')->where('key', $key)->value('id');

            if ($permission !== null && $hr !== null) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $hr, 'permission_id' => $permission, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if ($hr !== null) {
            Cache::forget("role_{$hr}_permission_keys");
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        foreach (DB::table('roles')->pluck('id') as $role) {
            Cache::forget("role_{$role}_permission_keys");
        }
    }
};
