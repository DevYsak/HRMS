<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regularisation requests go straight to HR, and HR's approval applies them.
 *
 * Adds the "Approve regularisations (HR)" permission — the one key that may
 * decide a regularisation (managers and directors hold approve_regularisation
 * too, so that key cannot mean "HR") — granted to the HR Admin role. Super
 * Admin holds every permission implicitly; any other role can be given it in
 * Roles & Permissions.
 *
 * Requests already waiting at the old Manager Review / Admin Approval stages
 * are relabelled HR Review: only the label changes, nothing is approved,
 * rejected or applied. Idempotent.
 */
return new class extends Migration
{
    private const KEY = 'hr_approve_regularisation';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'label' => 'Approve Regularisations (HR)',
            'description' => 'Approve or reject attendance and leave regularisation requests; an approval applies the correction',
            'module' => 'Attendance',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permission = DB::table('permissions')->where('key', self::KEY)->value('id');
        $hr = DB::table('roles')->where('slug', 'hr_admin')->value('id');

        if ($permission !== null && $hr !== null) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $hr, 'permission_id' => $permission, 'created_at' => $now, 'updated_at' => $now,
            ]);
            Cache::forget("role_{$hr}_permission_keys");
        }

        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->string('stage')->default('hr_review')->change();
        });

        DB::table('attendance_regularisations')
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', '!=', 'hr_review'))
            ->update(['stage' => 'hr_review', 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->string('stage')->default('manager_review')->change();
        });

        $id = DB::table('permissions')->where('key', self::KEY)->value('id');

        if ($id !== null) {
            DB::table('role_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
