<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('comp_off_credits', 8, 2)->default(0)->after('encashed_days');
            $table->unique(['employee_id', 'leave_type_id', 'year'], 'leave_balances_employee_leave_type_year_unique');
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropUnique('leave_balances_employee_id_leave_type_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropUnique('leave_balances_employee_leave_type_year_unique');
            $table->unique(['employee_id', 'leave_type_id']);
            $table->dropColumn('comp_off_credits');
        });
    }
};
