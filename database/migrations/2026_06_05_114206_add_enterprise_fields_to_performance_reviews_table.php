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
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->foreignId('performance_cycle_id')->nullable()->constrained('performance_cycles')->nullOnDelete()->after('review_cycle_id');
            $table->foreignId('template_id')->nullable()->constrained('performance_templates')->nullOnDelete()->after('performance_cycle_id');
            $table->decimal('final_score', 5, 2)->nullable()->after('overall_rating');
            $table->enum('grade', ['a_plus', 'a', 'b', 'c', 'd'])->nullable()->after('final_score');
            $table->decimal('attendance_score', 5, 2)->nullable()->after('grade');
            $table->decimal('punctuality_score', 5, 2)->nullable()->after('attendance_score');
            $table->decimal('leave_impact_score', 5, 2)->nullable()->after('punctuality_score');
            $table->foreignId('hr_reviewer_id')->nullable()->constrained('users')->nullOnDelete()->after('reviewer_id');
            $table->text('hr_comments')->nullable()->after('manager_feedback');
            $table->foreignId('department_head_id')->nullable()->constrained('users')->nullOnDelete()->after('hr_reviewer_id');
            $table->text('dept_head_comments')->nullable()->after('hr_comments');
            $table->timestamp('self_submitted_at')->nullable()->after('submitted_at');
            $table->timestamp('manager_submitted_at')->nullable()->after('self_submitted_at');
            $table->timestamp('hr_submitted_at')->nullable()->after('manager_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->dropForeign(['performance_cycle_id']);
            $table->dropForeign(['template_id']);
            $table->dropForeign(['hr_reviewer_id']);
            $table->dropForeign(['department_head_id']);
            $table->dropColumn([
                'performance_cycle_id', 'template_id', 'final_score', 'grade',
                'attendance_score', 'punctuality_score', 'leave_impact_score',
                'hr_reviewer_id', 'hr_comments', 'department_head_id', 'dept_head_comments',
                'self_submitted_at', 'manager_submitted_at', 'hr_submitted_at',
            ]);
        });
    }
};
