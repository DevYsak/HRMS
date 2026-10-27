<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR edits to a leave request, kept as revisions: the version the employee
 * submitted, the version HR proposes, who proposed it and when, and the
 * employee's answer. A materially changed request waits for the employee in
 * 'awaiting_employee_confirmation' — nothing is deducted until final approval.
 * Additive only: no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            // The request as it stood, and as HR proposes it.
            $table->json('original');
            $table->json('proposed');
            $table->json('changed_fields');
            $table->text('reason');
            // Material changes (dates, days, type, paid/unpaid split) need the employee's confirmation.
            $table->boolean('requires_confirmation')->default(true);
            // awaiting_employee | accepted | rejected | withdrawn | applied
            $table->string('status', 24)->default('awaiting_employee');
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->text('response_note')->nullable();
            $table->timestamps();

            $table->index(['leave_request_id', 'status']);
            $table->index(['status', 'employee_id']);
        });

        DB::statement("ALTER TABLE leave_requests MODIFY status ENUM('pending','pending_hr','approved','rejected','cancelled','withdrawn','more_info_requested','awaiting_employee_confirmation') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // A request still waiting on the employee goes back to HR rather than failing the rollback.
        DB::table('leave_requests')->where('status', 'awaiting_employee_confirmation')->update(['status' => 'pending_hr']);
        DB::statement("ALTER TABLE leave_requests MODIFY status ENUM('pending','pending_hr','approved','rejected','cancelled','withdrawn','more_info_requested') NOT NULL DEFAULT 'pending'");

        Schema::dropIfExists('leave_request_revisions');
    }
};
