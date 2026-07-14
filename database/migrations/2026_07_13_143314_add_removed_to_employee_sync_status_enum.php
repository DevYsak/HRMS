<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add a 'removed' state to employees.sync_status so an offboarded
     * employee's biometric enrolment can be marked as released.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE employees MODIFY sync_status ENUM('pending', 'synced', 'failed', 'removed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE employees SET sync_status = 'pending' WHERE sync_status = 'removed'");
        DB::statement("ALTER TABLE employees MODIFY sync_status ENUM('pending', 'synced', 'failed') NOT NULL DEFAULT 'pending'");
    }
};
