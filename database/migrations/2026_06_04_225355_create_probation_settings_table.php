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
        Schema::create('probation_settings', function (Blueprint $table) {
            $table->id();
            // NULL = global default (applies when no per-type rule exists)
            $table->foreignId('employment_type_id')
                ->nullable()
                ->unique()
                ->constrained('employment_types')
                ->nullOnDelete();
            $table->unsignedSmallInteger('probation_days')->default(90);
            $table->boolean('auto_calculate')->default(true);
            $table->boolean('requires_manager_review')->default(true);
            $table->boolean('requires_hr_approval')->default(true);
            $table->unsignedSmallInteger('extension_max_days')->default(90);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('probation_settings');
    }
};
