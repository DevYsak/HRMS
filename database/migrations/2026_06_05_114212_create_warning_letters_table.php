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
        Schema::create('warning_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->enum('warning_type', ['verbal', 'first_written', 'final_written', 'pip', 'termination_review']);
            $table->text('reason');
            $table->text('description')->nullable();
            $table->date('issue_date');
            $table->date('next_review_date')->nullable();
            $table->string('attachment_path')->nullable();
            $table->enum('status', ['draft', 'issued', 'acknowledged', 'under_review', 'closed'])->default('draft');
            $table->text('hr_comments')->nullable();
            $table->text('manager_comments')->nullable();
            $table->foreignId('previous_warning_id')->nullable()->constrained('warning_letters')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('warning_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warning_letters');
    }
};
