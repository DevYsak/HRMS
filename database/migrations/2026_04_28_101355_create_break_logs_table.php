<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendances')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->dateTime('break_start');
            $table->dateTime('break_end')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->timestamps();

            $table->index(['attendance_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_logs');
    }
};
