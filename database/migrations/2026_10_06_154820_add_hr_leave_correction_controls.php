<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR leave corrections, done safely.
 *
 * - leave_balance_adjustments.document_path: an optional supporting
 *   document for an HR adjustment, stored on the private disk. Additive and
 *   nullable — no existing row changes.
 * - Two permissions, granted to HR Admin by default:
 *     manage_approved_leave  correct or cancel an already-approved request
 *                            (the ledger usage is reversed and re-posted)
 *     record_approved_leave  record leave on an employee's behalf as
 *                            already approved (still validated by the rules)
 *
 * Idempotent; down() removes the permissions and the column.
 */
return new class extends Migration
{
    /** @var array<string, array{label: string, description: string}> */
    private const PERMISSIONS = [
        'manage_approved_leave' => ['label' => 'Correct / Cancel Approved Leave', 'description' => 'Change the dates or type of approved leave, or cancel it; the balance is reversed and re-posted through the ledger'],
        'record_approved_leave' => ['label' => 'Record Approved Leave on Behalf', 'description' => 'Record leave for an employee as already approved (the leave rules still apply)'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('leave_balance_adjustments', 'document_path')) {
            Schema::table('leave_balance_adjustments', function (Blueprint $table) {
                $table->string('document_path', 500)->nullable()->after('internal_note');
            });
        }

        $now = now();
        $hr = DB::table('roles')->where('slug', 'hr_admin')->value('id');

        foreach (self::PERMISSIONS as $key => $def) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, ...$def, 'module' => 'Leave Management', 'created_at' => $now, 'updated_at' => $now,
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

        if (Schema::hasColumn('leave_balance_adjustments', 'document_path')) {
            Schema::table('leave_balance_adjustments', function (Blueprint $table) {
                $table->dropColumn('document_path');
            });
        }
    }
};
