<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "No cap" and "no carry-forward" are opposite instructions.
 *
 * max_carry_over_days was NOT NULL DEFAULT 0, so zero had to stand for both —
 * and the field was never read by anything, which is the only reason that had
 * not yet produced a wrong answer. Making it canonical without separating the
 * two meanings would have capped every carry-forward at zero: the exact
 * opposite of the HR-controlled process it is meant to govern.
 *
 *   NULL  unlimited — HR decides the amount, no numeric ceiling
 *   0     no carry-forward permitted for this policy
 *   > 0   cap in days
 *
 * UK Standard is set to unlimited per the approved decision. Unlimited removes
 * the numeric ceiling, not the approval: carry forward still requires an
 * explicit HR decision, and still cannot exceed the recorded previous-year
 * balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw DDL rather than doctrine/dbal: the column keeps its precision and
        // only nullability changes.
        DB::statement('ALTER TABLE leave_policies MODIFY max_carry_over_days DECIMAL(5,2) NULL DEFAULT NULL');

        // Every existing policy holds 0, which under the old schema was
        // ambiguous. The approved reading for UK Standard is unlimited.
        DB::table('leave_policies')
            ->where('name', 'UK Standard')
            ->update(['max_carry_over_days' => null]);
    }

    public function down(): void
    {
        // Unlimited has no representation in the old schema; 0 is the only
        // value the column can take, which is why it meant two things.
        DB::table('leave_policies')->whereNull('max_carry_over_days')->update(['max_carry_over_days' => 0]);

        DB::statement('ALTER TABLE leave_policies MODIFY max_carry_over_days DECIMAL(5,2) NOT NULL DEFAULT 0.00');
    }
};
