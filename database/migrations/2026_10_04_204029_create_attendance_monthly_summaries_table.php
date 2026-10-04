<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §7 generate-attendance-summary: the previous month's attendance,
 * summarised per employee on the 1st. The job computed these figures and
 * discarded them; this is where they are kept. One row per employee per
 * month, upserted, so a re-run replaces rather than duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_monthly_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // 'Y-m'
            $table->unsignedSmallInteger('days_recorded')->default(0);
            $table->unsignedSmallInteger('present_days')->default(0);
            $table->unsignedSmallInteger('late_days')->default(0);
            $table->unsignedSmallInteger('excess_break_days')->default(0);
            $table->unsignedSmallInteger('missing_checkouts')->default(0);
            $table->decimal('total_hours', 7, 2)->default(0);
            $table->unsignedInteger('total_break_minutes')->default(0);
            $table->decimal('leave_days', 5, 1)->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_monthly_summaries');
    }
};
