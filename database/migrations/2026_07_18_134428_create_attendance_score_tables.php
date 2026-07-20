<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // HR-configurable scoring weights (Rule 11) — singleton row. Every
        // factor's points live here so scoring policy changes need no deploy.
        Schema::create('attendance_score_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('late_arrival_penalty', 4, 1)->default(3.0);
            $table->decimal('late_per_30m_penalty', 4, 1)->default(1.0);      // extra per full 30 min late
            $table->decimal('early_exit_penalty', 4, 1)->default(3.0);
            $table->decimal('missing_punch_penalty', 4, 1)->default(10.0);
            $table->decimal('auto_punch_out_penalty', 4, 1)->default(5.0);
            $table->decimal('regularization_penalty', 4, 1)->default(1.0);
            $table->decimal('break_violation_penalty', 4, 1)->default(2.0);
            $table->decimal('short_hours_penalty', 4, 1)->default(5.0);       // worked < 90% of standard
            $table->decimal('overtime_bonus', 4, 1)->default(1.0);
            $table->decimal('holiday_work_bonus', 4, 1)->default(2.0);
            $table->timestamps();
        });

        // One engine-computed score per employee per day, with the full
        // deduction/bonus breakdown persisted as the audit trail.
        Schema::create('attendance_daily_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('score', 5, 2);                    // 0–100
            $table->string('status', 30);                      // present | late | absent | half_day …
            $table->json('breakdown');                         // [{factor, label, points, detail}, …]
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_scores');
        Schema::dropIfExists('attendance_score_settings');
    }
};
