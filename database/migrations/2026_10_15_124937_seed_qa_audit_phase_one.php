<?php

use Database\Seeders\QaAuditSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seed the QA & Audit tracker with the six audit phases and the verified
 * Phase 1 record (test cases, the 440-test regression run, findings and
 * their fixing commits). QA records only; idempotent — rows that already
 * exist are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new QaAuditSeeder)->run();
    }

    public function down(): void
    {
        // The tables themselves are dropped by create_qa_audit_tables.
    }
};
