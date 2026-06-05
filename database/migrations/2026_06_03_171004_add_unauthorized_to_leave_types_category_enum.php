<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL requires a full column redefinition to expand an ENUM.
        DB::statement("
            ALTER TABLE `leave_types`
            MODIFY `category` ENUM(
                'annual','sick','mdl','comp_off','encashment','unpaid','other','unauthorized'
            ) NOT NULL DEFAULT 'other'
        ");
    }

    public function down(): void
    {
        // Revert to original enum — rows with 'unauthorized' would be lost; safe in dev.
        DB::statement("
            ALTER TABLE `leave_types`
            MODIFY `category` ENUM(
                'annual','sick','mdl','comp_off','encashment','unpaid','other'
            ) NOT NULL DEFAULT 'other'
        ");
    }
};
