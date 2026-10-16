<?php

use Database\Seeders\QaAuditPhaseThreeSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record Phase 3 (cross-module consistency) in the QA tracker: test cases,
 * findings, fix commits, retests, and the open WFH decision (QA-F-037).
 * QA records only; idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new QaAuditPhaseThreeSeeder)->run();
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
