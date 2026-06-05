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
        Schema::create('employee_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('performance_components')->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('target_value', 8, 2)->nullable();
            $table->decimal('achieved_value', 8, 2)->nullable();
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->enum('status', ['not_started', 'in_progress', 'achieved', 'missed'])->default('not_started');
            $table->text('notes')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'component_id', 'performance_cycle_id'], 'uq_emp_kpi_cycle');
            $table->index(['employee_id', 'performance_cycle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_kpis');
    }
};
