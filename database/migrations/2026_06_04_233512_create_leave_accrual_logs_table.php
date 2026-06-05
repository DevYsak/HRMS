<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_accrual_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('days_credited', 5, 2);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1–12
            $table->timestamp('credited_at');
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year', 'month'], 'leave_accrual_unique');
            $table->index(['employee_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_accrual_logs');
    }
};
