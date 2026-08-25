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
        Schema::create('payroll_run_failures', function (Blueprint $table) {
            $table->id();
            $table->string('month');
            $table->unsignedSmallInteger('year');
            $table->string('cycle');
            $table->foreignId('attempted_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['month', 'year', 'cycle']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_run_failures');
    }
};
