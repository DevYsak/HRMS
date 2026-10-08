<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary-cycle moves take effect at the next payroll month, never immediately.
 *
 * Moving an already-paid employee straight into the other cycle let one
 * payroll month pay them twice (October A and October B) or not at all. The
 * move is now held as pending until its effective month; the old cycle's
 * last paid day is kept so the first new-cycle run counts absences from the
 * next day — no day deducted twice, none skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'pending_salary_cycle_id')) {
                $table->foreignId('pending_salary_cycle_id')->nullable()->after('salary_cycle_id')
                    ->constrained('salary_cycles')->nullOnDelete();
            }
            if (! Schema::hasColumn('employees', 'salary_cycle_effective_month')) {
                // Y-m: the first payroll month paid in the new cycle.
                $table->string('salary_cycle_effective_month', 7)->nullable()->after('pending_salary_cycle_id');
            }
            if (! Schema::hasColumn('employees', 'salary_cycle_paid_through')) {
                // Last day the previous cycle paid for.
                $table->date('salary_cycle_paid_through')->nullable()->after('salary_cycle_effective_month');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_salary_cycle_id');
            $table->dropColumn(['salary_cycle_effective_month', 'salary_cycle_paid_through']);
        });
    }
};
