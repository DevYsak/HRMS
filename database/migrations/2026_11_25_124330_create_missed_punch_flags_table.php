<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee who went over the weekly missed-punch allowance, one row per
 * Monday–Friday week: when they were flagged, the count and allowance at the
 * time, and HR's review (who, when, note). The flag history HR works from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missed_punch_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->unsignedSmallInteger('missed_count');
            $table->unsignedTinyInteger('allowance');
            $table->timestamp('flagged_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'week_start']);
            $table->index(['week_start', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missed_punch_flags');
    }
};
