<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave V2 balance migration: one row per employee, Earned Leave type and
 * leave year — the audit record of what was carried into Earned Leave, from
 * where, by whom, and the key that makes the migration idempotent. A zero
 * balance has a row too, so provisioning never grants a fresh 12 days on top.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_v2_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_year_id')->constrained();
            $table->foreignId('earned_leave_type_id')->constrained('leave_types');
            $table->foreignId('earned_balance_id')->nullable()->constrained('leave_balances')->nullOnDelete();
            $table->foreignId('source_leave_type_id')->nullable()->constrained('leave_types');
            $table->foreignId('source_balance_id')->nullable()->constrained('leave_balances')->nullOnDelete();
            $table->decimal('source_available', 8, 2)->nullable();
            $table->decimal('migrated_available', 8, 2);
            $table->decimal('policy_entitlement', 8, 2)->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('leave_ledger_entries')->nullOnDelete();
            $table->date('effective_date');
            $table->string('migration_key', 150)->unique();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['employee_id', 'earned_leave_type_id', 'leave_year_id'], 'leave_v2_migrations_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_v2_migrations');
    }
};
