<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
 *
 * ROLLBACK. The old column cannot hold NULL, so a naive down() writes 0 over
 * every unlimited policy — turning "no ceiling" into "nothing may be carried",
 * the one substitution this migration exists to prevent, and doing it
 * silently. Instead down() parks unlimited policies at a sentinel the old
 * column can hold and no real cap ever takes, and up() reads it back. Rolling
 * back and re-applying therefore returns every policy to the state it was
 * actually in, not to this migration's opening assumption.
 *
 * The park and restore steps are separate, static and data-only so they can be
 * tested without running DDL — MySQL commits implicitly on DDL, which would
 * dissolve the transaction a test is wrapped in and leak into the next one.
 */
return new class extends Migration
{
    /**
     * Stands for "was unlimited" while the column is NOT NULL.
     *
     * A cap is a number of days, so it can never legitimately be negative.
     * Nothing reads this column as a limit without first passing through
     * up(), which clears the sentinel: it is a marker, never a value anything
     * calculates with.
     */
    public const UNLIMITED_SENTINEL = -1;

    /**
     * Record which policies are unlimited, in a form the old column accepts.
     *
     * @return int how many were parked
     */
    public static function parkUnlimited(): int
    {
        return DB::table('leave_policies')
            ->whereNull('max_carry_over_days')
            ->update(['max_carry_over_days' => self::UNLIMITED_SENTINEL]);
    }

    /**
     * Give the parked policies their unlimited state back.
     *
     * @return int how many were restored; zero means this is a first
     *             application rather than a re-apply
     */
    public static function restoreUnlimited(): int
    {
        return DB::table('leave_policies')
            ->where('max_carry_over_days', self::UNLIMITED_SENTINEL)
            ->update(['max_carry_over_days' => null]);
    }

    public function up(): void
    {
        // Raw DDL rather than doctrine/dbal: the column keeps its precision and
        // only nullability changes.
        DB::statement('ALTER TABLE leave_policies MODIFY max_carry_over_days DECIMAL(5,2) NULL DEFAULT NULL');

        $restored = self::restoreUnlimited();

        // First application: nothing was parked, so apply the approved
        // decision. Skipped when restoring, because what the policies actually
        // held outranks this migration's opening assumption.
        if ($restored === 0) {
            DB::table('leave_policies')
                ->where('name', 'UK Standard')
                ->update(['max_carry_over_days' => null]);
        }
    }

    public function down(): void
    {
        // Park before the column loses its ability to hold NULL. Writing 0
        // here would say "no carry-forward permitted", which is the opposite
        // of what these policies mean.
        self::parkUnlimited();

        DB::statement('ALTER TABLE leave_policies MODIFY max_carry_over_days DECIMAL(5,2) NOT NULL DEFAULT 0.00');
    }
};
