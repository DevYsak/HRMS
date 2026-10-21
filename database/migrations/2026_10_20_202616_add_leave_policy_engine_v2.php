<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave V2 (policy engine): additive columns and one new table.
 *
 * Nothing existing is renamed, rewritten or removed. Every new column is
 * nullable or defaulted so historical leave types, policies, rules and
 * requests read exactly as before; the new behaviour is used only while the
 * leave_policy_engine_v2 flag is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // Employee-facing grouping: earned (paid leave subtypes), comp_off, unpaid.
            // Null = derived from the legacy category / is_paid (LeaveCategory::of).
            $table->string('leave_category', 20)->nullable()->after('category')->index();
            $table->boolean('counts_toward_annual_cap')->default(false)->after('leave_category');
            $table->text('description')->nullable()->after('name');
            $table->string('unit', 10)->default('days')->after('counts_toward_annual_cap');
            $table->string('icon', 64)->nullable()->after('color');
            $table->unsignedSmallInteger('display_order')->default(0)->after('icon');
            // Offered for NEW requests. History of an inactive type stays readable.
            $table->boolean('is_active')->default(true)->after('display_order');
        });

        Schema::table('leave_policies', function (Blueprint $table) {
            $table->decimal('annual_paid_leave_cap', 6, 2)->nullable()->after('description');
            // jul_jun (current company leave year) | jan_dec | apr_mar | anniversary | custom
            $table->string('policy_year_type', 20)->nullable()->after('annual_paid_leave_cap');
            $table->unsignedTinyInteger('policy_year_start_month')->nullable()->after('policy_year_type');
            $table->unsignedTinyInteger('policy_year_start_day')->nullable()->after('policy_year_start_month');
            $table->date('effective_from')->nullable()->after('policy_year_start_day');
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        Schema::table('leave_policy_rules', function (Blueprint $table) {
            // block | convert_to_lwp — what happens when a request exceeds the balance.
            $table->string('excess_handling', 20)->nullable()->after('max_balance');
            $table->decimal('negative_balance_limit', 5, 2)->nullable()->after('excess_handling');
            // Attachment required once a request reaches this many days (null = per attachment_required).
            $table->decimal('attachment_after_days', 5, 2)->nullable()->after('attachment_required');
            $table->unsignedSmallInteger('notice_days')->nullable()->after('attachment_after_days');
            $table->boolean('allow_past_dates')->nullable()->after('notice_days');
            $table->unsignedSmallInteger('past_days_limit')->nullable()->after('allow_past_dates');
            $table->unsignedSmallInteger('future_days_limit')->nullable()->after('past_days_limit');
            $table->boolean('encashment_enabled')->nullable()->after('future_days_limit');
            // excluded (default) | counted — how weekly offs / holidays inside a range are charged.
            $table->string('weekend_treatment', 20)->nullable()->after('encashment_enabled');
            $table->string('holiday_treatment', 20)->nullable()->after('weekend_treatment');
            // {"steps":["manager"],"conditions":[{"min_days":3,"steps":["manager","hr"]}]}
            $table->json('approval_workflow')->nullable()->after('holiday_treatment');
        });

        Schema::create('leave_policy_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_policy_id')->constrained()->cascadeOnDelete();
            // company | department | employee  (future: location, employment_type, job_title)
            $table->string('scope', 20);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope', 'scope_id', 'effective_from'], 'leave_policy_assign_scope_idx');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            // Portion of `days` charged as Leave Without Pay because the balance
            // could not cover it (policy excess_handling = convert_to_lwp).
            // Null/0 on every historical request: paid portion = days.
            $table->decimal('lwp_days', 5, 2)->nullable()->after('days');
            // The approval steps the policy required when submitted, e.g. ["manager","hr"].
            $table->json('approval_path')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['lwp_days', 'approval_path']);
        });

        Schema::dropIfExists('leave_policy_assignments');

        Schema::table('leave_policy_rules', function (Blueprint $table) {
            $table->dropColumn([
                'excess_handling', 'negative_balance_limit', 'attachment_after_days', 'notice_days',
                'allow_past_dates', 'past_days_limit', 'future_days_limit', 'encashment_enabled',
                'weekend_treatment', 'holiday_treatment', 'approval_workflow',
            ]);
        });

        Schema::table('leave_policies', function (Blueprint $table) {
            $table->dropColumn([
                'annual_paid_leave_cap', 'policy_year_type', 'policy_year_start_month',
                'policy_year_start_day', 'effective_from', 'effective_to',
            ]);
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropIndex(['leave_category']);
            $table->dropColumn([
                'leave_category', 'counts_toward_annual_cap', 'description', 'unit', 'icon', 'display_order', 'is_active',
            ]);
        });
    }
};
