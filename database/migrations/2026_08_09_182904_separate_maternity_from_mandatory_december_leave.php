<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stop using "mdl" as the category for Maternity Leave.
 *
 * MDL means Mandatory December Leave — the six December shutdown days — and
 * that mechanism is december_mandatory_days, not a leave type at all. The
 * category was nonetheless attached to Maternity Leave, so the employee's
 * "MDL" balance card showed maternity while the policy text on the same page
 * described the December shutdown. One identifier, two meanings.
 *
 * Maternity Leave keeps its ML code and becomes category 'maternity'.
 * DecemberMandatoryDay is untouched and remains the source of truth for
 * shutdown dates and the comp-off credit earned by working one.
 *
 * Three phases, in this order, because MySQL rejects an ENUM that omits a
 * value rows still hold: add 'maternity', move the rows, then drop 'mdl'.
 */
return new class extends Migration
{
    /** The enum as it stands, minus the category being changed. */
    private const SHARED = "'annual','sick','comp_off','encashment','unpaid','other','unauthorized','bereavement','paternity','wfh','lwp','custom'";

    public function up(): void
    {
        // 1 — widen so both values are legal at once.
        DB::statement('ALTER TABLE `leave_types` MODIFY `category` ENUM('.self::SHARED.",'mdl','maternity') NOT NULL DEFAULT 'other'");

        // 2 — move the rows.
        $moved = DB::table('leave_types')->where('category', 'mdl')->update(['category' => 'maternity']);

        // 3 — refuse to narrow the enum while anything still holds the old
        // value. Dropping it then would silently coerce those rows.
        $remaining = DB::table('leave_types')->where('category', 'mdl')->count();
        if ($remaining > 0) {
            throw new RuntimeException("Aborting: {$remaining} leave_types still hold category 'mdl' after moving {$moved}.");
        }

        DB::statement('ALTER TABLE `leave_types` MODIFY `category` ENUM('.self::SHARED.",'maternity') NOT NULL DEFAULT 'other'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `leave_types` MODIFY `category` ENUM('.self::SHARED.",'mdl','maternity') NOT NULL DEFAULT 'other'");

        DB::table('leave_types')->where('category', 'maternity')->update(['category' => 'mdl']);

        DB::statement('ALTER TABLE `leave_types` MODIFY `category` ENUM('.self::SHARED.",'mdl') NOT NULL DEFAULT 'other'");
    }
};
