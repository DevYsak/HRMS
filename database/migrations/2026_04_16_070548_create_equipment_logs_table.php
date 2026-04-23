<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('item_name');
            $table->string('serial_number')->nullable();
            $table->text('description')->nullable();
            $table->enum('action', ['issued', 'returned', 'lost', 'damaged'])->default('issued');
            $table->date('action_date');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_logs');
    }
};
