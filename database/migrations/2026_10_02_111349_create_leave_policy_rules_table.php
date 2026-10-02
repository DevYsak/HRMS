<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-leave-type rules inside a named leave policy (Phase 2B).
 *
 * LeavePolicy stays the named policy an employee is on (and keeps the UK
 * weeks-based Annual Leave settings). A rule says, for one leave type under
 * that policy, how the entitlement is reached, how it accrues, how it carries
 * forward and who may use it. Every nullable column falls back to the leave
 * type's own legacy setting, so a policy only states what it changes.
 *
 * Resolution: employee override → allocation (group) policy → this rule →
 * the leave type itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_policy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_policy_id')->constrained('leave_policies')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();

            // Entitlement: uk_engine (Annual Leave weeks) | fixed_days | none
            $table->string('entitlement_method', 20)->default('fixed_days');
            $table->decimal('fixed_days', 6, 2)->nullable();

            // Accrual: annual_upfront | monthly | quarterly
            $table->string('accrual_method', 20)->default('annual_upfront');
            $table->decimal('accrual_amount', 6, 2)->nullable();
            // Day of the month the scheduled accrual credits (1–28).
            $table->unsignedTinyInteger('accrual_day')->default(1);
            // Joining month: full (counts in full) | half_month (counts if joined
            // on or before the 15th) | none (accrual starts the month after).
            $table->string('joining_month_rule', 20)->default('half_month');
            // joining | confirmation (probation completed)
            $table->string('accrual_start', 20)->default('joining');
            $table->decimal('max_accumulation', 6, 2)->nullable();

            // Carry forward (null = use the leave type's setting).
            $table->boolean('carry_forward_enabled')->nullable();
            $table->decimal('carry_forward_max_days', 6, 2)->nullable();
            $table->decimal('carry_forward_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('carry_forward_expiry_months')->nullable();
            // A fixed MM-DD expiry within the new year, e.g. 09-30.
            $table->string('carry_forward_expiry_date', 5)->nullable();
            $table->decimal('max_balance', 6, 2)->nullable();
            $table->json('carry_forward_eligible_statuses')->nullable();

            // Usage rules (null = use the leave type's setting).
            $table->boolean('probation_restricted')->nullable();
            $table->boolean('notice_period_restricted')->nullable();
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();
            $table->boolean('attachment_required')->nullable();
            $table->boolean('allow_half_day')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['leave_policy_id', 'leave_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policy_rules');
    }
};
