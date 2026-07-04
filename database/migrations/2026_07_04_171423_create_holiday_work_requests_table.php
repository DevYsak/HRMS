<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Request to Work on Holiday" workflow. Mirrors AttendanceRegularisation:
 * an employee requests to work a company holiday; on approval the service
 * materialises a holiday-worked attendance plus the chosen pay (overtime /
 * comp-off). Also extends attendances.status with 'holiday_worked'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_work_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('holiday_id')->nullable()->constrained('public_holidays')->nullOnDelete();
            $table->date('work_date');
            $table->text('reason');
            $table->string('work_location', 20)->default('office'); // office | wfh | client_site
            $table->decimal('expected_hours', 4, 2)->default(8);
            $table->string('project')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comments')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('pay_type', 20)->default('overtime'); // overtime | comp_off | double_pay | extra_leave | half_day
            $table->string('status', 20)->default('pending');    // pending | approved | rejected | cancelled
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reviewer_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->index('status');
        });

        // Extend the attendance status vocabulary with 'holiday_worked'.
        DB::statement("ALTER TABLE attendances MODIFY status ENUM('on_time','late','half_day','absent','remote','holiday_worked') NOT NULL DEFAULT 'on_time'");

        // Allow 'holiday' as an OT-request source (OT auto-created from approved holiday work).
        DB::statement("ALTER TABLE ot_requests MODIFY source ENUM('manual','nexflow','biometric','regularisation','holiday') NOT NULL DEFAULT 'manual'");
    }

    public function down(): void
    {
        DB::statement("UPDATE ot_requests SET source = 'manual' WHERE source = 'holiday'");
        DB::statement("ALTER TABLE ot_requests MODIFY source ENUM('manual','nexflow','biometric','regularisation') NOT NULL DEFAULT 'manual'");
        DB::statement("UPDATE attendances SET status = 'on_time' WHERE status = 'holiday_worked'");
        DB::statement("ALTER TABLE attendances MODIFY status ENUM('on_time','late','half_day','absent','remote') NOT NULL DEFAULT 'on_time'");
        Schema::dropIfExists('holiday_work_requests');
    }
};
