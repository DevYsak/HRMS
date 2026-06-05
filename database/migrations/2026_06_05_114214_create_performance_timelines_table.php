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
        Schema::create('performance_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('event_type', [
                'kpi_assigned',
                'review_started',
                'self_review_submitted',
                'manager_review_submitted',
                'review_completed',
                'scorecard_locked',
                'warning_issued',
                'warning_acknowledged',
                'pip_created',
                'pip_milestone',
                'pip_outcome',
                'promotion_recommended',
                'promotion_approved',
                'salary_revised',
                'transfer',
                'award',
                'recognition',
            ]);
            $table->date('event_date');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_visible_to_employee')->default(true);
            $table->timestamps();

            $table->index(['employee_id', 'event_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_timelines');
    }
};
