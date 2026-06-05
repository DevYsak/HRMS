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
        Schema::create('performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('template_id')->constrained('performance_templates')->cascadeOnDelete();
            $table->enum('cycle_type', ['monthly', 'quarterly', 'half_yearly', 'annual', 'custom'])->default('quarterly');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('self_review_deadline')->nullable();
            $table->date('manager_review_deadline')->nullable();
            $table->date('hr_review_deadline')->nullable();
            $table->enum('status', [
                'draft',
                'active',
                'self_review',
                'manager_review',
                'hr_review',
                'completed',
                'locked',
            ])->default('draft');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_cycles');
    }
};
