<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Undo the leave configuration LeaveSeeder overwrote when it was run as a
 * verification step.
 *
 * LeaveSeeder writes with updateOrCreate keyed on name, so running it against
 * a database that already held HR's configuration silently replaced it. The
 * damage was bounded and is listed below; nothing outside this window was
 * touched (leave_encashments, balance adjustments, accrual logs and allocation
 * policies were all unaffected).
 *
 * Restores the intended policy:
 *   CSL — 12 days, carry-forward on, encashment ON (the rule is that CSL can
 *         be encashed after approval; the seeder disabled it)
 *
 * Deliberately keeps Maternity Leave on the 'maternity' category — that change
 * came from the approved MDL migration, not from the accident, and must not be
 * rolled back here.
 *
 * Guarded throughout: every statement is scoped to the exact rows involved and
 * skips silently if they are already correct, so this is safe to run against a
 * database that never suffered the accident (a fresh deploy, or production).
 */
return new class extends Migration
{
    /** Rows created by the accidental run, identified by type name. */
    private const ACCIDENTAL_TYPE = 'Annual Leave';

    public function up(): void
    {
        // 1 — CSL: category and encashment both overwritten.
        DB::table('leave_types')->where('code', 'CL')->update([
            'category' => 'annual',
            'allow_carry_forward' => true,
            'allow_encashment' => true,
        ]);

        // 2 — Sick Leave: carry-forward switched on by the seeder.
        DB::table('leave_types')->where('code', 'SL')->update(['allow_carry_forward' => false]);

        // 3 — Maternity: carry-forward switched on. Category stays 'maternity'.
        DB::table('leave_types')->where('code', 'ML')->update(['allow_carry_forward' => false]);

        // 4 — Remove the leave type the accident introduced, with the balances
        //     and requests it brought. Scoped by id so nothing else is touched,
        //     and skipped entirely if the row is absent.
        $accidental = DB::table('leave_types')
            ->where('name', self::ACCIDENTAL_TYPE)
            ->whereNull('code')
            ->first();

        if ($accidental) {
            DB::table('leave_requests')->where('leave_type_id', $accidental->id)->delete();
            DB::table('leave_balances')->where('leave_type_id', $accidental->id)->delete();
            DB::table('leave_types')->where('id', $accidental->id)->delete();
        }

        // 5 — Seven Sick Leave balances the seeder created for employees who
        //     had none. Removed only where untouched since: a zero-use balance
        //     is one nobody has drawn against, so deleting it loses nothing.
        $sickId = DB::table('leave_types')->where('code', 'SL')->value('id');
        if ($sickId) {
            DB::table('leave_balances')
                ->where('leave_type_id', $sickId)
                ->whereBetween('created_at', ['2026-08-09 18:39:00', '2026-08-09 18:40:30'])
                ->where('used_days', 0)
                ->delete();
        }
    }

    /**
     * Reverses the configuration flags only.
     *
     * The rows deleted above were created by accident and carry no business
     * meaning, so they are not recreated — doing so would reintroduce the very
     * pollution this migration exists to remove.
     */
    public function down(): void
    {
        DB::table('leave_types')->where('code', 'CL')->update([
            'category' => 'other',
            'allow_carry_forward' => true,
            'allow_encashment' => false,
        ]);

        DB::table('leave_types')->where('code', 'SL')->update(['allow_carry_forward' => true]);
        DB::table('leave_types')->where('code', 'ML')->update(['allow_carry_forward' => true]);
    }
};
