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
        Schema::create('performance_review_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('performance_reviews')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('performance_components')->cascadeOnDelete();
            $table->decimal('self_score', 5, 2)->nullable();
            $table->decimal('manager_score', 5, 2)->nullable();
            $table->decimal('hr_score', 5, 2)->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->decimal('weighted_score', 5, 2)->nullable();
            $table->text('self_comment')->nullable();
            $table->text('manager_comment')->nullable();
            $table->text('hr_comment')->nullable();
            $table->boolean('auto_computed')->default(false);
            $table->timestamps();

            $table->unique(['review_id', 'component_id']);
            $table->index('review_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_review_scores');
    }
};
