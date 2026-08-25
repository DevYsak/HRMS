<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The working week was hardcoded to "Sunday only" in ~20 places, so a company
 * on a five-day week had every Saturday counted as an absence — understating
 * attendance and overstating loss of pay.
 *
 * Stored as an array of Carbon dayOfWeek numbers (0 = Sunday … 6 = Saturday).
 * Defaults to [0] so existing behaviour is unchanged until an admin opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->json('weekly_off_days')->nullable()->after('shift_end');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('weekly_off_days');
        });
    }
};
