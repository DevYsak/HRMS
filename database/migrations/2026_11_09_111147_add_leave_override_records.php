<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records for the Earned Leave / HR approval workflow (9 Nov 2026):
 *
 *  - leave_requests.balance_snapshot: what the employee had when they applied
 *    (balance today, expected accrual, what this request could use, the paid /
 *    unpaid split) — kept as submitted, never recalculated.
 *  - email_logs.context_type / context_id: which record an email was about,
 *    so a leave request lists every email sent for it.
 *  - leave_types.hr_remark_required: off by default — an HR remark on a paid /
 *    unpaid change is optional unless HR switches it on for a type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->json('balance_snapshot')->nullable()->after('lwp_days');
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('context_type', 40)->nullable()->after('notifiable_id');
            $table->unsignedBigInteger('context_id')->nullable()->after('context_type');
            $table->index(['context_type', 'context_id']);
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('hr_remark_required')->default(false)->change();
        });
        DB::table('leave_types')->where('hr_remark_required', true)->update(['hr_remark_required' => false]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('hr_remark_required')->default(true)->change();
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['context_type', 'context_id']);
            $table->dropColumn(['context_type', 'context_id']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('balance_snapshot');
        });
    }
};
