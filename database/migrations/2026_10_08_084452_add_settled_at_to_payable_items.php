<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an approved payable item was actually paid (D8, 8 Oct 2026).
 *
 * Approved money is carried into the next open payroll run when its own
 * period's run has already closed. Each item already records its source
 * period (month / payout_month / work_date), its approval date and the run
 * that pays it (payroll_id, or payslip_id for overtime); settled_at records
 * the moment that run was finalised.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['incentives', 'reimbursements', 'leave_encashments', 'overtime_records'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'settled_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->timestamp('settled_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'settled_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('settled_at');
                });
            }
        }
    }
};
