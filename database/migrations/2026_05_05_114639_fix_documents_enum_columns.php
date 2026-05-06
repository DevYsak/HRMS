<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forward-fix: extend documents table enums to include all values the app uses.
 *
 * category: added 'personal' (employee personal docs) and 'payslip' (payslip docs)
 * visibility: added 'restricted' as preferred alias (code uses 'restricted', DB had 'individual')
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `documents`
            MODIFY COLUMN `category`
            ENUM('policy','contract','form','notice','other','personal','payslip')
            NOT NULL DEFAULT 'other'");

        DB::statement("ALTER TABLE `documents`
            MODIFY COLUMN `visibility`
            ENUM('all','department','individual','restricted')
            NOT NULL DEFAULT 'all'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `documents`
            MODIFY COLUMN `category`
            ENUM('policy','contract','form','notice','other')
            NOT NULL DEFAULT 'other'");

        DB::statement("ALTER TABLE `documents`
            MODIFY COLUMN `visibility`
            ENUM('all','department','individual')
            NOT NULL DEFAULT 'all'");
    }
};
