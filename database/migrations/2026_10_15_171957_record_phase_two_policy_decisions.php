<?php

use Database\Seeders\QaAuditPhaseTwoDecisionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record the Phase 2 policy decisions (approved 15 Oct 2026) in the QA
 * tracker: fixed findings verified with their commits, decided ones closed
 * with the decision, new items opened. QA records only; idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new QaAuditPhaseTwoDecisionsSeeder)->run();
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
