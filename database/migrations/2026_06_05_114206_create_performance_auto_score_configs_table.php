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
        Schema::create('performance_auto_score_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('performance_templates')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('performance_components')->cascadeOnDelete();
            $table->enum('source_type', [
                'attendance_pct',
                'late_marks',
                'early_exits',
                'unauthorized_leave',
                'lwp',
                'approved_leave',
                'shift_compliance',
            ]);
            $table->enum('formula', ['linear', 'threshold', 'inverse'])->default('linear');
            $table->decimal('full_score_at', 5, 2)->default(100);
            $table->decimal('zero_score_at', 5, 2)->default(70);
            $table->timestamps();

            $table->unique(['template_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_auto_score_configs');
    }
};
