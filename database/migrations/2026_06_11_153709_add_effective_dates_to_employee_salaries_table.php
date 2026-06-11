<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('amount');
            $table->date('effective_to')->nullable()->after('effective_from');

            // New composite index added first so the employee_id foreign key
            // retains a supporting index once the unique below is dropped.
            $table->index(['employee_id', 'salary_component_id', 'effective_from'], 'employee_salaries_emp_component_effective_idx');

            // Drop the old single-combination unique so multiple time-ranged rows can exist
            $table->dropUnique(['employee_id', 'salary_component_id']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->unique(['employee_id', 'salary_component_id']);
            $table->dropIndex('employee_salaries_emp_component_effective_idx');
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};
