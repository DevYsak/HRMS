<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend enum to include pending_finance (required by finance approval workflow)
        // Flow: draft → pending_finance → finalized
        DB::statement("ALTER TABLE payrolls MODIFY COLUMN status ENUM('draft','pending_finance','finalized') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE payrolls MODIFY COLUMN status ENUM('draft','finalized') NOT NULL DEFAULT 'draft'");
    }
};
