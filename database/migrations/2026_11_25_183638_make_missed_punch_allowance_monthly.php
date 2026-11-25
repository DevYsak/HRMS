<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The missed-punch allowance counts the calendar month, not the week
 * (26 Nov 2026: "month wise allow 5 missed punches"). The setting becomes
 * missed_punch_monthly_allowance, set to 5. A flag now names its period:
 * flags already raised keep their week ("week"), new ones are per month
 * ("month") — so the flag history still reads right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->renameColumn('missed_punch_weekly_allowance', 'missed_punch_monthly_allowance');
        });
        DB::table('attendance_settings')->update(['missed_punch_monthly_allowance' => 5]);

        Schema::table('missed_punch_flags', function (Blueprint $table) {
            $table->renameColumn('week_start', 'period_start');
        });
        Schema::table('missed_punch_flags', function (Blueprint $table) {
            $table->string('period', 10)->default('month')->after('employee_id');
        });
        DB::table('missed_punch_flags')->update(['period' => 'week']);

        // The new unique index starts with employee_id, so the foreign key keeps
        // an index when the old one goes.
        Schema::table('missed_punch_flags', function (Blueprint $table) {
            $table->unique(['employee_id', 'period', 'period_start']);
            $table->index(['period_start', 'reviewed_at']);
        });
        Schema::table('missed_punch_flags', function (Blueprint $table) {
            $table->dropUnique('missed_punch_flags_employee_id_week_start_unique');
            $table->dropIndex('missed_punch_flags_week_start_reviewed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('missed_punch_flags', function (Blueprint $table) {
            $table->unique(['employee_id', 'period_start'], 'missed_punch_flags_employee_id_week_start_unique');
            $table->index(['period_start', 'reviewed_at'], 'missed_punch_flags_week_start_reviewed_at_index');
        });
        Schema::table('missed_punch_flags', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'period', 'period_start']);
            $table->dropIndex(['period_start', 'reviewed_at']);
            $table->dropColumn('period');
        });
        Schema::table('missed_punch_flags', function (Blueprint $table) {
            $table->renameColumn('period_start', 'week_start');
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->renameColumn('missed_punch_monthly_allowance', 'missed_punch_weekly_allowance');
        });
    }
};
