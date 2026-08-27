<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three settings that were booleans pretending to be policies.
 *
 * payment_mode: is_paid is a yes/no, and real payroll has partially paid leave
 * and leave whose treatment depends on the employee's policy. Neither could be
 * expressed, so they were approximated by the nearest boolean.
 *
 * sandwich_mode: is_sandwich_applicable only ever meant "bridge weekends".
 * Bridging public holidays, or every non-working day, had no representation —
 * so a company wanting one of those got the other.
 *
 * carry_forward_mode: allow_carry_forward said whether a type could be carried,
 * never how. The agreed process is HR approval for everything, and that
 * intention had nowhere to live.
 *
 * Existing booleans are preserved and seeded across, so nothing changes
 * behaviour on this migration alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // paid | unpaid | partial | policy
            $table->string('payment_mode', 20)->default('paid')->after('is_paid');

            // off | weekends | weekends_holidays | all_non_working | custom
            $table->string('sandwich_mode', 24)->default('off')->after('is_sandwich_applicable');

            // none | hr_approval | automatic
            $table->string('carry_forward_mode', 20)->default('none')->after('allow_carry_forward');
        });

        // Seed the new columns from what the booleans already say, so this
        // migration describes the current configuration rather than replacing it.
        DB::table('leave_types')->where('is_paid', false)->update(['payment_mode' => 'unpaid']);
        DB::table('leave_types')->where('is_sandwich_applicable', true)->update(['sandwich_mode' => 'weekends']);
        DB::table('leave_types')->where('allow_carry_forward', true)->update(['carry_forward_mode' => 'hr_approval']);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['payment_mode', 'sandwich_mode', 'carry_forward_mode']);
        });
    }
};
