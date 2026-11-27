<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The day missed-punch tracking starts (Attendance Settings). Before it no
 * missed punch is counted toward the monthly allowance, alerted, flagged or
 * scored per punch. Conexus starts it on 1 December 2026; empty means every
 * day is tracked.
 */
return new class extends Migration
{
    public const CONEXUS_START = '2026-12-01';

    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->date('missed_punch_starts_on')->nullable()->after('missed_punch_warning_at');
        });

        DB::table('attendance_settings')->update(['missed_punch_starts_on' => self::CONEXUS_START, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('missed_punch_starts_on');
        });
    }
};
