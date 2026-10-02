<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * carry_forward_mode defaulted to 'none' and could not be null, so a leave
 * type created with allow_carry_forward = true and no explicit mode was
 * stored as carrying forward and, at the same time, as never carrying
 * forward. Two columns describing the same thing, disagreeing, with the mode
 * winning — the type silently stopped being carried.
 *
 * That did not show while nothing read the mode. It appeared the moment the
 * engine started honouring it.
 *
 * Null now means "not explicitly configured", and the type falls back to the
 * older allow_carry_forward flag: enabled types default to HR approval, which
 * is the company policy. An explicit 'none' still means never, because
 * somebody chose it.
 *
 * Rows already carrying a mode are untouched — the earlier migration
 * backfilled every enabled type to hr_approval, so existing configuration is
 * already correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE leave_types MODIFY carry_forward_mode VARCHAR(20) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        // Anything unset becomes the old default before the column stops
        // accepting null. This restores the previous shape exactly, including
        // its ambiguity.
        DB::table('leave_types')->whereNull('carry_forward_mode')->update(['carry_forward_mode' => 'none']);

        DB::statement("ALTER TABLE leave_types MODIFY carry_forward_mode VARCHAR(20) NOT NULL DEFAULT 'none'");
    }
};
