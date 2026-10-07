<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A holiday-work approval only authorises the work. Pay (overtime or comp-off)
 * is settled once the day has GENUINE attendance — a valid Face IN and final
 * ID Card OUT — from the actual worked duration. These record when that
 * happened and the real hours used, so a repeat run never pays twice.
 *
 * Purely additive: two nullable columns, no data rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holiday_work_requests', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('attendance_id');
            $table->decimal('actual_hours', 5, 2)->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('holiday_work_requests', function (Blueprint $table) {
            $table->dropColumn(['settled_at', 'actual_hours']);
        });
    }
};
