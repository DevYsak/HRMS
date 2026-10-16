<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Late corrections to a closed payslip's unpaid days (Phase 4 policy): leave
 * approved, cancelled or re-classified, or an absence regularised after the
 * run closed. Each row is one arrear (more unpaid days → deduction) or refund
 * (fewer → earning) for a source payslip, applied in a later run. The ledger
 * makes it idempotent: the next delta is measured against the snapshot plus
 * everything already applied, and a draft re-run replaces its own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_day_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('applied_payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->decimal('days', 6, 2);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'source_payslip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_day_adjustments');
    }
};
