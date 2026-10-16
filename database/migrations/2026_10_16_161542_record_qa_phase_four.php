<?php

use Database\Seeders\QaAuditPhaseFourSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record Phase 4 (payroll, settings, theme, notifications, AI, reports,
 * date/time) in the QA tracker: test cases, verified fixes and the open
 * payroll / policy findings. QA records only; idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new QaAuditPhaseFourSeeder)->run();
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
