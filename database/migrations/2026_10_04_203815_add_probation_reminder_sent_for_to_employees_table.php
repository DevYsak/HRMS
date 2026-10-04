<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The probation end date a "review due in 10 days" reminder was last sent
 * for (spec §7 check-probation-due). The daily job re-sent the same reminder
 * every day of the 10-day window; with this it sends once per end date — and
 * again only if the probation is extended to a new date. Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('probation_reminder_sent_for')->nullable()->after('probation_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('probation_reminder_sent_for');
        });
    }
};
