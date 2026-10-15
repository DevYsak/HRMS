<?php

use Database\Seeders\QaAuditPhaseTwoSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record Phase 2 (org structure & data quality) in the QA tracker: its
 * test cases, runs (including the 15 Oct full suite), code findings and
 * policy questions. QA records only; idempotent. Data issues are recorded
 * per environment with `php artisan qa:record-data-quality`.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new QaAuditPhaseTwoSeeder)->run();
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
