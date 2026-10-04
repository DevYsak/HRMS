<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which payroll run paid an encashment.
 *
 * Without it a draft re-run could not tell "already in this run" from "paid
 * by an earlier run": encashments were flipped to processed on the first
 * draft and silently dropped from every regenerated payslip. Incentives,
 * reimbursements and exit settlements already carry payroll_id; this brings
 * encashments in line. Additive and nullable — existing rows keep their
 * status and are never re-paid (a processed row with no payroll_id is
 * treated as paid by an earlier run).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->foreignId('payroll_id')->nullable()->after('payout_month')->constrained('payrolls')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_id');
        });
    }
};
