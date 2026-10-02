<?php

use App\Services\Leave\HistoricalAdjustmentYearMapper;
use Illuminate\Database\Migrations\Migration;

/**
 * Historical-balance adjustments written before leave_year_id existed get
 * their year from the audit entry recorded with them. Unmappable rows stay
 * null and the ledger backfill sends the affected balances to HR review.
 * Only fills nulls, so re-running changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(HistoricalAdjustmentYearMapper::class)->run();
    }

    public function down(): void
    {
        // Data fill only; the column itself belongs to an earlier migration.
    }
};
