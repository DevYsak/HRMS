<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payslip_items MODIFY type ENUM('earning','deduction','employer_contribution') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE payslip_items MODIFY type ENUM('earning','deduction') NOT NULL");
    }
};
