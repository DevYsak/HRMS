<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'regularisation' as an OT request source so approved attendance
     * regularisations can auto-file overtime for the corrected hours.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE ot_requests MODIFY source ENUM('manual', 'nexflow', 'biometric', 'regularisation') NOT NULL DEFAULT 'manual'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE ot_requests MODIFY source ENUM('manual', 'nexflow', 'biometric') NOT NULL DEFAULT 'manual'");
    }
};
