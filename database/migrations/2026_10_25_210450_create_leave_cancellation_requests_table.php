<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's request to cancel APPROVED leave. The leave stays approved
 * (and deducted) until HR decides; HR approving it cancels the leave through
 * the normal audited path. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->text('reason');
            // pending | approved | rejected | withdrawn
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['leave_request_id', 'status']);
            $table->index(['status', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_cancellation_requests');
    }
};
