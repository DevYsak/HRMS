<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee-specific exception to their policy's entitlement (Phase 2B).
 *
 * "Policy Annual Leave = 20, this employee +5" without cloning a policy for
 * one person. mode add adds days to what the policy resolves; mode set
 * replaces it. Scoped to one leave year, or open-ended from effective_from
 * when leave_year_id is null. Never deleted: revoking stamps revoked_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leave_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('leave_year_id')->nullable()->constrained('leave_years')->nullOnDelete();
            $table->string('mode', 10)->default('add');
            $table->decimal('days', 6, 2);
            $table->date('effective_from')->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'leave_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_overrides');
    }
};
