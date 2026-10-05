<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The month's denominator, stated: scheduled working days (weekly offs,
 * holidays and MDL dates excluded), the weekly offs themselves, and the
 * weekly offs actually worked — kept separate, never counted as scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_monthly_summaries', function (Blueprint $table) {
            $table->unsignedSmallInteger('scheduled_days')->default(0)->after('days_recorded');
            $table->unsignedSmallInteger('weekly_off_days')->default(0)->after('scheduled_days');
            $table->unsignedSmallInteger('weekly_off_worked_days')->default(0)->after('weekly_off_days');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_monthly_summaries', function (Blueprint $table) {
            $table->dropColumn(['scheduled_days', 'weekly_off_days', 'weekly_off_worked_days']);
        });
    }
};
