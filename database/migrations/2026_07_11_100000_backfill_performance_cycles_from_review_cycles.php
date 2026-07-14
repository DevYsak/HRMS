<?php

use App\Services\Performance\LegacyCycleMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Finish the ReviewCycle → PerformanceCycle cutover: mirror legacy cycles
 * into performance_cycles and backfill performance_reviews.performance_cycle_id.
 * Pure data migration — no schema change; legacy rows are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_cycles') || ! Schema::hasTable('performance_cycles')) {
            return;
        }

        app(LegacyCycleMigrator::class)->migrate();
    }

    public function down(): void
    {
        // Data backfill — intentionally irreversible (legacy columns untouched).
    }
};
