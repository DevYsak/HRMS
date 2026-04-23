<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ot_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->decimal('total_hours_worked', 5, 2);
            $table->decimal('standard_hours', 4, 2)->default(9.00);
            $table->decimal('ot_hours', 4, 2);
            $table->decimal('rate_per_hour', 8, 2)->default(100.00);
            $table->decimal('ot_amount', 10, 2);
            $table->boolean('is_paid')->default(false);
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->index(['is_paid', 'payslip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_records');
    }
};
