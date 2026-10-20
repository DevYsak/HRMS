<?php

use Database\Seeders\QaAuditPhaseFiveSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record Phase 5 round 1 (UI/UX, responsive, dark mode) in the QA tracker:
 * test cases, fixed findings with their commits and retests, and the open
 * decisions. QA records only; idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new QaAuditPhaseFiveSeeder)->run();
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
