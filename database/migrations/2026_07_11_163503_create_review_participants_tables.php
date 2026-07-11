<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4 Phase D — multi-reviewer performance reviews.
 *
 * review_weightages: default (department_id null) and per-department reviewer
 * weights. review_participants: one row per reviewer on a review, each rating
 * the same component set independently via review_participant_scores. The
 * wide self/manager columns on performance_review_scores stay as mirrors for
 * existing dashboards; the composite comes from participants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_weightages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reviewer_role', 30); // self | team_lead | department_head | additional
            $table->decimal('weight_percent', 5, 2);
            $table->timestamps();

            $table->unique(['department_id', 'reviewer_role']);
        });

        Schema::create('review_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_review_id')->constrained('performance_reviews')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reviewer_role', 30); // self | team_lead | department_head | additional
            $table->decimal('weight_percent', 5, 2);
            $table->string('status', 20)->default('pending'); // pending | submitted
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['performance_review_id', 'reviewer_id']);
            $table->index(['reviewer_id', 'status']);
        });

        Schema::create('review_participant_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('review_participants')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('performance_components')->cascadeOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['participant_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_participant_scores');
        Schema::dropIfExists('review_participants');
        Schema::dropIfExists('review_weightages');
    }
};
