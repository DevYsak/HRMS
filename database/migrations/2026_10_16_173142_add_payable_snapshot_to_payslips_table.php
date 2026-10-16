<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payable-days figures a payslip was calculated from (Phase 4 policy):
 * eligible / paid / LOP days, leave impact, the per-day rate and payable OT
 * hours. Written when the payslip is generated and never recomputed after
 * finalisation — the PDF reads it, so later attendance or leave edits cannot
 * silently change an old payslip. Payslips generated before this have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->json('payable_snapshot')->nullable()->after('net_salary');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('payable_snapshot');
        });
    }
};
