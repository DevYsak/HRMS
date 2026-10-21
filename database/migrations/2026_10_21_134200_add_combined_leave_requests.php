<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave V2 — Combined Leave: one request, each date allocated full or half,
 * paid or unpaid.
 *
 * Additive only. leave_requests keeps the header (days = paid + unpaid
 * units, lwp_days = unpaid units), so the existing single balance debit and
 * pending reservation charge the paid units exactly once. leave_request_days
 * holds one row per date of the range — the allocation payroll and
 * attendance read. Standard requests have no day rows and request_mode NULL;
 * nothing historical changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // NULL = a standard request; 'combined' = date-wise allocation.
            $table->string('request_mode', 20)->nullable()->after('main_category')->index();
        });

        Schema::create('leave_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // working | weekly_off | holiday | shutdown
            $table->string('day_status', 20);
            // full_paid | full_unpaid | half_paid | half_unpaid | excluded
            $table->string('allocation_type', 20);
            $table->decimal('paid_units', 4, 2)->default(0);
            $table->decimal('unpaid_units', 4, 2)->default(0);
            // first_half | second_half, for a half day
            $table->string('session', 20)->nullable();
            // Per-date reason (future option); NULL = the request's reason.
            $table->foreignId('reason_leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete();
            $table->boolean('is_excluded')->default(false);
            $table->string('exclusion_reason')->nullable();
            $table->foreignId('balance_leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['leave_request_id', 'date']);
            $table->index('date');
        });

        Schema::table('leave_policies', function (Blueprint $table) {
            // HR's Combined Leave rules for the policy; NULL = not offered.
            $table->json('combined_leave_rules')->nullable()->after('earned_leave_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('leave_policies', function (Blueprint $table) {
            $table->dropColumn('combined_leave_rules');
        });

        Schema::dropIfExists('leave_request_days');

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['request_mode']);
            $table->dropColumn('request_mode');
        });
    }
};
