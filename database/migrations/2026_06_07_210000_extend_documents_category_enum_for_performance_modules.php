<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forward-fix: route Warning Letter, PIP, Promotion, and Performance Review
 * artifacts through the existing documents module instead of module-local
 * file columns, per the v3.1 audit gap closure.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `documents`
            MODIFY COLUMN `category`
            ENUM('policy','contract','form','notice','other','personal','payslip','warning_letter','pip','promotion','performance_review')
            NOT NULL DEFAULT 'other'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `documents`
            MODIFY COLUMN `category`
            ENUM('policy','contract','form','notice','other','personal','payslip')
            NOT NULL DEFAULT 'other'");
    }
};
