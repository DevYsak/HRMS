<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('pf_enabled')->default(false);
            $table->string('pf_number', 30)->nullable();
            $table->boolean('esi_enabled')->default(false);
            $table->string('esi_number', 30)->nullable();
            $table->boolean('professional_tax_enabled')->default(false);
            $table->boolean('tds_enabled')->default(false);
            $table->boolean('ot_eligible')->default(true);
            $table->boolean('incentive_eligible')->default(true);
            $table->boolean('bonus_eligible')->default(true);
            $table->boolean('reimbursement_eligible')->default(true);
            $table->boolean('hra_enabled')->default(false);
            $table->decimal('hra_percentage', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_settings');
    }
};
