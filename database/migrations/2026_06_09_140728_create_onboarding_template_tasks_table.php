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
        Schema::create('onboarding_template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('onboarding_templates')->cascadeOnDelete();
            $table->enum('phase', ['onboarding', 'offboarding'])->default('onboarding');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('general');
            $table->string('owner_role')->nullable();
            $table->unsignedSmallInteger('due_days')->default(7);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('auto_trigger')->nullable();
            $table->timestamps();

            $table->index(['template_id', 'phase', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_template_tasks');
    }
};
