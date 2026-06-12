<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_payroll_settings', function (Blueprint $table) {
            $table->decimal('ctc', 12, 2)->nullable();
            $table->foreignId('salary_structure_id')->nullable()->constrained('salary_structures')->nullOnDelete();
            $table->string('uan_number', 30)->nullable();
            $table->decimal('ot_rate_per_hour', 8, 2)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('ifsc_code', 20)->nullable();
            $table->string('pan_number', 20)->nullable();
            $table->string('aadhar_number', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_payroll_settings', function (Blueprint $table) {
            $table->dropForeign(['salary_structure_id']);
            $table->dropColumn([
                'ctc',
                'salary_structure_id',
                'uan_number',
                'ot_rate_per_hour',
                'bank_name',
                'account_number',
                'ifsc_code',
                'pan_number',
                'aadhar_number',
            ]);
        });
    }
};
