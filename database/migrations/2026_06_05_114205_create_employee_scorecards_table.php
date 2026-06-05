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
        Schema::create('employee_scorecards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('performance_templates')->cascadeOnDelete();
            $table->decimal('total_weighted_score', 5, 2)->default(0);
            $table->decimal('attendance_score', 5, 2)->nullable();
            $table->decimal('punctuality_score', 5, 2)->nullable();
            $table->decimal('leave_impact', 5, 2)->nullable();
            $table->decimal('manager_rating', 5, 2)->nullable();
            $table->decimal('hr_rating', 5, 2)->nullable();
            $table->decimal('final_score', 5, 2)->default(0);
            $table->enum('grade', ['a_plus', 'a', 'b', 'c', 'd'])->nullable();
            $table->unsignedSmallInteger('rank_in_department')->nullable();
            $table->unsignedSmallInteger('rank_in_company')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'performance_cycle_id']);
            $table->index('performance_cycle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_scorecards');
    }
};
