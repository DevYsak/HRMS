<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical record of every leave balance movement (Phase 2A).
 *
 * Append-only. A credit (base entitlement, carry forward, accrual, add-on,
 * adjustment credit, opening balance) is a positive row; a debit (usage,
 * encashment, expiry, adjustment debit) is a negative row. A correction is a
 * new row that points at the one it reverses — history is never edited.
 *
 * The domain records (leave requests, adjustments, carry-forward
 * transactions, accrual logs, encashments) stay the reason a movement
 * happened; source_type/source_id point back at them. leave_balances is a
 * summary that can always be rebuilt from these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_year_id')->constrained('leave_years')->cascadeOnDelete();

            $table->string('entry_type', 30);
            $table->string('bucket', 20);
            // Signed: credits positive, debits negative.
            $table->decimal('days', 8, 2);
            $table->date('effective_date');
            // Only credits expire (carry forward, add-on).
            $table->date('expires_on')->nullable();

            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('leave_ledger_entries')->restrictOnDelete();

            // One movement, one row: a retried job or a double click cannot
            // post the same movement twice.
            $table->string('idempotency_key', 150)->unique();

            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'leave_type_id', 'leave_year_id'], 'leave_ledger_balance_idx');
            $table->index(['source_type', 'source_id']);
            $table->index('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger_entries');
    }
};
