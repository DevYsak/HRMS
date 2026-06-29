<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * attendance_daily_summaries — authoritative per-day attendance figures
 * calculated by the external Python attendance engine and pushed into HRMS
 * via POST /api/v1/attendance/sync.
 *
 * Additive by design: it never replaces the existing `attendances` table
 * (web/biometric clock-in path). It stores the Python engine's daily roll-up
 * keyed by (employee_id, date) so HRMS reporting can read pre-calculated data
 * without re-computing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Device PIN the row was matched on — stored for traceability/debugging.
            $table->unsignedSmallInteger('employee_code');
            $table->date('date');

            // Punch window (full datetimes so cross-midnight shifts are unambiguous).
            $table->dateTime('first_punch')->nullable();
            $table->dateTime('last_punch')->nullable();

            // Pre-calculated figures from the Python engine.
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->decimal('working_hours', 5, 2)->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);

            // Free-form status from the engine: present, absent, late, half_day,
            // leave, holiday, weekly_off, etc. Kept as a string (not an enum) so
            // the Python engine owns the vocabulary without forcing HRMS migrations.
            $table->string('status', 30)->default('present');

            // Provenance.
            $table->string('device_serial')->nullable();
            $table->unsignedSmallInteger('raw_punch_count')->default(0);
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_summaries');
    }
};
