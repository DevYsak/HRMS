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
        Schema::create('performance_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('performance_categories')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('performance_templates')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('scoring_type', [
                'manual',
                'auto_attendance',
                'auto_punctuality',
                'auto_leave',
                'auto_productivity',
                'auto_shift_compliance',
            ])->default('manual');
            $table->decimal('max_score', 5, 2)->default(100);
            $table->decimal('weight_percent', 5, 2);
            $table->decimal('target_value', 8, 2)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index('template_id');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_components');
    }
};
