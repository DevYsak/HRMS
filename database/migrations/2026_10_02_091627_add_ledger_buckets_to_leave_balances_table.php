<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * leave_balances becomes the per-year summary of the ledger (Phase 2A).
 *
 * The buckets that allocated_days used to blur together are now separate.
 * allocated_days keeps its meaning — the net of every credit — so every
 * existing screen and query keeps working.
 *
 * A row is ledger-backed once ledger_migrated_at is set; from then on its
 * figures are only ever written by a rebuild from the ledger. ledger_status
 * says whether its history could be decomposed (safe) or is held as an
 * opening balance awaiting HR (needs_hr_review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('base_days', 8, 2)->default(0)->after('allocated_days');
            $table->decimal('accrued_days', 8, 2)->default(0)->after('carried_forward_days');
            $table->decimal('add_on_days', 8, 2)->default(0)->after('accrued_days');
            $table->decimal('adjustment_credit_days', 8, 2)->default(0)->after('add_on_days');
            $table->decimal('adjustment_debit_days', 8, 2)->default(0)->after('adjustment_credit_days');
            $table->decimal('opening_days', 8, 2)->default(0)->after('adjustment_debit_days');
            $table->decimal('expired_days', 8, 2)->default(0)->after('opening_days');

            $table->string('ledger_status', 20)->nullable()->after('expired_days');
            $table->text('ledger_review_reason')->nullable()->after('ledger_status');
            $table->timestamp('ledger_migrated_at')->nullable()->after('ledger_review_reason');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn([
                'base_days', 'accrued_days', 'add_on_days', 'adjustment_credit_days', 'adjustment_debit_days',
                'opening_days', 'expired_days', 'ledger_status', 'ledger_review_reason', 'ledger_migrated_at',
            ]);
        });
    }
};
