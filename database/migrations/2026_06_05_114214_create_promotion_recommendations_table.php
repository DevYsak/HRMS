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
        Schema::create('promotion_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommended_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('performance_review_id')->nullable()->constrained('performance_reviews')->nullOnDelete();
            $table->enum('recommendation_type', ['promotion', 'salary_increment', 'bonus', 'role_change', 'dept_transfer']);
            $table->string('current_role')->nullable();
            $table->string('proposed_role')->nullable();
            $table->decimal('current_salary', 12, 2)->nullable();
            $table->decimal('proposed_salary', 12, 2)->nullable();
            $table->decimal('bonus_amount', 12, 2)->nullable();
            $table->foreignId('target_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->text('justification');
            $table->enum('current_reviewer_stage', ['hr', 'dept_head', 'super_admin'])->default('hr');
            $table->enum('status', [
                'draft',
                'pending_hr',
                'pending_dept_head',
                'pending_super_admin',
                'approved',
                'rejected',
            ])->default('draft');
            $table->text('hr_comments')->nullable();
            $table->text('dept_head_comments')->nullable();
            $table->text('super_admin_comments')->nullable();
            $table->date('effective_date')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('recommendation_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_recommendations');
    }
};
