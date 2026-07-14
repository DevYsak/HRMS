<?php

use App\Services\Performance\ParticipantService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * v4 Phase D data backfill: convert legacy wide-column review scores
 * (self_score / manager_score) into per-reviewer participant rows.
 * Wide columns are kept as mirrors — no data is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_participants')) {
            return;
        }

        app(ParticipantService::class)->migrateWideScores();
    }

    public function down(): void
    {
        // Data backfill — intentionally irreversible (wide columns untouched).
    }
};
