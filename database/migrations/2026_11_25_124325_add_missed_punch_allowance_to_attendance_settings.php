<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly missed-punch rules, set by HR on Attendance Settings: how many
 * missed punches a Monday–Friday week allows before the employee is flagged
 * for HR review, and when an early warning goes out (0 = no early warning).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('missed_punch_weekly_allowance')->default(5);
            $table->unsignedTinyInteger('missed_punch_warning_at')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['missed_punch_weekly_allowance', 'missed_punch_warning_at']);
        });
    }
};
