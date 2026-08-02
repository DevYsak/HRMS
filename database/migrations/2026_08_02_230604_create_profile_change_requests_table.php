<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee-raised changes to approval-tier profile fields.
 *
 * Mirrors the shape attendance_regularisations already uses — status, reviewer,
 * comment, attachment, claim-lock — so the request joins the existing Approval
 * Center rather than needing a parallel inbox.
 *
 * v1 is single-step (HR approves). `stage` is deliberately absent: adding a
 * manager → HR chain later means adding the column, not reshaping the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            // Registry key, e.g. 'address'. Not an FK — the registry is code.
            $table->string('field', 60);
            // Kept as text so any field type round-trips; the old value is
            // retained so the reviewer sees a real before/after diff.
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reviewer_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // Same claim-lock contract as the other approval queues.
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_requests');
    }
};
