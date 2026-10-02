<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which credit each debit was taken from (Phase 2A credit-lot tracking).
 *
 * A credit's remaining amount is its days minus every consumption row that
 * points at it. That is what makes expiry exact: on 30 September only the
 * part of the carried-forward credit that was never used expires.
 *
 * credit_entry_id is null for the part of a debit no credit covered — a real
 * negative balance, recorded rather than floored away. A reversal adds rows
 * with negative days against the same credits, returning days to the source
 * they came from. Append-only, like the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_credit_consumptions', function (Blueprint $table) {
            $table->id();
            // Wording: the entry doing the consuming (or un-consuming, for a reversal).
            $table->foreignId('debit_entry_id')->constrained('leave_ledger_entries')->cascadeOnDelete();
            $table->foreignId('credit_entry_id')->nullable()->constrained('leave_ledger_entries')->cascadeOnDelete();
            $table->decimal('days', 8, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index('credit_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_credit_consumptions');
    }
};
