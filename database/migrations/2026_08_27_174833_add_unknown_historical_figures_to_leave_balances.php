<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "We do not know" is not the same as "zero".
 *
 * The historical leave record is incomplete: for closed years we largely have a
 * closing balance and pending requests, not a reliable count of days actually
 * taken. Storing that gap as used_days = 0 makes the system assert something
 * nobody checked, and carry forward would then compute
 * allocated - 0 - 0 = allocated and offer HR the whole year as eligible.
 *
 * used_days and encashed_days stay NOT NULL because the live engine reads them
 * on every balance in every query. What is added is a record of whether the
 * figure is a measurement or a placeholder, so the difference survives into the
 * screen, the carry-forward preview and the audit trail.
 *
 * Both default to false: every balance the application has computed for itself
 * genuinely does know its own usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->boolean('used_days_unknown')->default(false)->after('used_days');
            $table->boolean('encashed_days_unknown')->default(false)->after('encashed_days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn(['used_days_unknown', 'encashed_days_unknown']);
        });
    }
};
