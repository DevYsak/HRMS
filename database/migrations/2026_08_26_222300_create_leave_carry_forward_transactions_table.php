<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of leave carried from one leave year into the next.
 *
 * The calculation already existed and is not repeated here: LeaveCarryOverService
 * owns "eligible = allocated - used - encashed, capped by policy". What was
 * missing was any trace of the decision. Carried days landed in
 * leave_balances.carried_forward_days as a bare number, so afterwards nobody
 * could say which year they came from, what the employee was originally
 * entitled to carry, whether HR had deliberately approved less, or who did it.
 *
 * One row per (employee, leave type, previous year, current year). The unique
 * index is the idempotency guarantee: applying twice updates the same row
 * rather than stacking a second credit on top of the first.
 *
 * eligible_days is what the engine calculated. applied_days is what HR
 * approved. They are deliberately separate columns — a policy may allow less
 * than the full amount, and losing the original calculation would make the
 * decision unauditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_carry_forward_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_leave_year_id')->constrained('leave_years')->cascadeOnDelete();
            $table->foreignId('current_leave_year_id')->constrained('leave_years')->cascadeOnDelete();

            // The previous year's position at the moment of calculation. Kept
            // so the preview can be reconstructed later, when those balances
            // may well have moved on.
            $table->decimal('previous_allocated_days', 8, 2)->default(0);
            $table->decimal('previous_used_days', 8, 2)->default(0);
            $table->decimal('previous_encashed_days', 8, 2)->default(0);

            $table->decimal('eligible_days', 8, 2)->default(0);
            $table->decimal('applied_days', 8, 2)->default(0);
            $table->decimal('reversed_days', 8, 2)->default(0);

            // eligible | applied | partially_applied | reversed | rejected | not_eligible
            $table->string('status', 32)->default('eligible');

            $table->string('reason')->nullable();

            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();

            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();

            $table->timestamps();

            $table->unique(
                ['employee_id', 'leave_type_id', 'previous_leave_year_id', 'current_leave_year_id'],
                'lcf_unique_employee_type_years'
            );

            // Named explicitly: the generated name runs past MySQL's 64-character
            // identifier limit and the migration fails on the index, not the table.
            $table->index(['current_leave_year_id', 'status'], 'lcf_year_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_carry_forward_transactions');
    }
};
