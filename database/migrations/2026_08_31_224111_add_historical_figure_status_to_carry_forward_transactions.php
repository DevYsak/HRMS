<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A carry-forward transaction could not say that it did not know.
 *
 * previous_used_days and previous_encashed_days are NOT NULL DEFAULT 0, so a
 * year whose usage was never recorded was written down as a year in which
 * nobody took any leave. That is the one substitution this whole workflow
 * exists to avoid: most closed years reach us as a closing balance and
 * nothing else, and a zero there is a claim, not a gap.
 *
 * eligible_days had the same problem from the other direction — it is a
 * calculated entitlement, and when the inputs are missing there is no number
 * to put in it. It becomes nullable, and null reads as "not calculable, HR
 * decides" rather than "nothing is eligible".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_carry_forward_transactions', function (Blueprint $table) {
            // 'known' | 'unknown' — whether the figure beside it is a record
            // or an absence.
            $table->string('used_status', 16)->default('known')->after('previous_used_days');
            $table->string('encashed_status', 16)->default('known')->after('previous_encashed_days');

            // What HR was told the year closed at, which is the ceiling for a
            // decision that cannot be derived. Distinct from
            // previous_allocated_days, which is the year's allocation.
            $table->decimal('historical_closing_balance', 8, 2)->nullable()->after('previous_encashed_days');
        });

        // Null means "no eligible amount could be calculated", which is not
        // the same as an eligible amount of zero.
        DB::statement('ALTER TABLE leave_carry_forward_transactions MODIFY eligible_days DECIMAL(8,2) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        // Rows whose eligibility was never calculable have no honest value in
        // a NOT NULL column. 0 is wrong — it would say nothing was eligible —
        // so they are removed rather than misstated: they exist only where
        // this migration's columns exist.
        DB::table('leave_carry_forward_transactions')->whereNull('eligible_days')->delete();

        DB::statement('ALTER TABLE leave_carry_forward_transactions MODIFY eligible_days DECIMAL(8,2) NOT NULL DEFAULT 0.00');

        Schema::table('leave_carry_forward_transactions', function (Blueprint $table) {
            $table->dropColumn(['used_status', 'encashed_status', 'historical_closing_balance']);
        });
    }
};
