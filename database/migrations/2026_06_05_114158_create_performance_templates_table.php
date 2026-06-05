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
        Schema::create('performance_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->enum('applies_to_type', ['role', 'department', 'designation', 'employment_type', 'shift', 'branch', 'global'])->default('global');
            $table->unsignedBigInteger('applies_to_id')->nullable();
            $table->enum('cycle_type', ['monthly', 'quarterly', 'half_yearly', 'annual', 'custom'])->default('quarterly');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['applies_to_type', 'applies_to_id']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_templates');
    }
};
