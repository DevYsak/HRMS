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
            $table->string('status')->default('draft')->change();
            $table->boolean('promotion_recommended')->default(false)->after('overall_rating');
            $table->text('manager_feedback')->nullable()->after('comments');
        });

        Schema::table('review_goals', function (Blueprint $table) {
            $table->foreignId('performance_review_id')->nullable()->constrained()->nullOnDelete()->after('employee_id');
            $table->tinyInteger('self_rating')->nullable()->after('description'); // 1-5
            $table->tinyInteger('manager_rating')->nullable()->after('self_rating'); // 1-5
            $table->text('self_comment')->nullable()->after('manager_rating');
            $table->text('manager_comment')->nullable()->after('self_comment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->dropColumn(['promotion_recommended', 'manager_feedback']);
        });

        Schema::table('review_goals', function (Blueprint $table) {
            $table->dropForeign(['performance_review_id']);
            $table->dropColumn(['performance_review_id', 'self_rating', 'manager_rating', 'self_comment', 'manager_comment']);
        });
    }
};
