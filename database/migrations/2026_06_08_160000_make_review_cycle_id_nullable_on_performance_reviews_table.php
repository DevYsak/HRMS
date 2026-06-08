<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->dropForeign(['review_cycle_id']);
        });

        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->foreignId('review_cycle_id')->nullable()->change();
        });

        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->foreign('review_cycle_id')->references('id')->on('review_cycles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->dropForeign(['review_cycle_id']);
        });

        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->foreignId('review_cycle_id')->nullable(false)->change();
        });

        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->foreign('review_cycle_id')->references('id')->on('review_cycles')->cascadeOnDelete();
        });
    }
};
