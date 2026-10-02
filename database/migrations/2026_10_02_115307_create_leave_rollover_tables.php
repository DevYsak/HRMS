<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Year-end rollover and bulk runs (Phase 2C).
 *
 * leave_bulk_runs records every bulk operation (rollover, reconciliation,
 * bulk provisioning, bulk add-on): who ran it, preview or applied, and the
 * summary HR saw. Ledger entries it posted point back at it via bulk_run_id.
 *
 * leave_rollover_records is the idempotency anchor of the year-end rollover:
 * one row per employee, leave type and closing year. A row that reached
 * 'processed' is never processed again, so re-running the rollover — or two
 * runs racing — cannot carry forward, expire or grant twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_bulk_runs', function (Blueprint $table) {
            $table->id();
            // rollover | reconciliation | bulk_provision | bulk_add_on
            $table->string('kind', 30);
            $table->foreignId('leave_year_id')->nullable()->constrained('leave_years')->nullOnDelete();
            $table->foreignId('target_leave_year_id')->nullable()->constrained('leave_years')->nullOnDelete();
            $table->boolean('dry_run')->default(true);
            // running | completed | completed_with_issues | failed
            $table->string('status', 30)->default('running');
            $table->json('summary')->nullable();
            $table->json('parameters')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'leave_year_id']);
        });

        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->foreignId('bulk_run_id')->nullable()->after('reverses_entry_id')->constrained('leave_bulk_runs')->nullOnDelete();
        });

        Schema::create('leave_rollover_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('from_leave_year_id')->constrained('leave_years')->cascadeOnDelete();
            $table->foreignId('to_leave_year_id')->constrained('leave_years')->cascadeOnDelete();
            $table->foreignId('bulk_run_id')->nullable()->constrained('leave_bulk_runs')->nullOnDelete();
            // processed | needs_hr_review | failed
            $table->string('status', 30);
            $table->decimal('closing_days', 8, 2)->nullable();
            $table->decimal('carry_days', 8, 2)->default(0);
            $table->decimal('expired_days', 8, 2)->default(0);
            $table->decimal('new_base_days', 8, 2)->nullable();
            $table->foreignId('carry_forward_transaction_id')->nullable()->constrained('leave_carry_forward_transactions')->nullOnDelete();
            $table->text('message')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'from_leave_year_id'], 'leave_rollover_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_rollover_records');

        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bulk_run_id');
        });

        Schema::dropIfExists('leave_bulk_runs');
    }
};
