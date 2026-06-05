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
        Schema::create('performance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('performance_templates')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('color', 20)->default('#6366F1');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_categories');
    }
};
