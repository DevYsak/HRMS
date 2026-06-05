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
        Schema::create('pip_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warning_letter_id')->nullable()->constrained('warning_letters')->nullOnDelete();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('hr_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_head_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('review_period_days')->default(90);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('action_plan')->nullable();
            $table->text('success_criteria')->nullable();
            $table->text('manager_notes')->nullable();
            $table->text('hr_notes')->nullable();
            $table->text('employee_comments')->nullable();
            $table->enum('current_reviewer_stage', ['manager', 'hr', 'dept_head', 'super_admin'])->default('manager');
            $table->enum('status', ['draft', 'active', 'under_review', 'extended', 'successful', 'failed', 'escalated'])->default('draft');
            $table->enum('outcome', ['successful', 'extended', 'failed', 'escalated'])->nullable();
            $table->date('outcome_date')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pip_records');
    }
};
