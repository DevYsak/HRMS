<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the payrolls uniqueness key to include the cycle.
 *
 * unique(month, year) predates the `cycle` column and was never dropped when
 * cycle was added, so a second cycle in a month that already had a payroll died
 * on a duplicate-key violation — cycle_b was unreachable. PayrollService already
 * resolves payrolls by month+year+cycle, so the key must match that lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropUnique('payrolls_month_year_unique');
            $table->unique(['month', 'year', 'cycle'], 'payrolls_month_year_cycle_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropUnique('payrolls_month_year_cycle_unique');
            $table->unique(['month', 'year'], 'payrolls_month_year_unique');
        });
    }
};
