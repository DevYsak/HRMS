<?php

use Database\Seeders\QaAuditWfhPolicySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record the P3-07 WFH policy decision and its fix in the QA tracker
 * (QA-F-037 → test cases → commit → retest → verified). QA records only;
 * idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new QaAuditWfhPolicySeeder)->run();
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
