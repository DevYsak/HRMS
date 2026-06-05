<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->enum('action', ['credit', 'debit']);
            $table->decimal('days', 6, 2);
            $table->decimal('previous_balance', 6, 2);
            $table->decimal('new_balance', 6, 2);
            $table->text('reason');
            $table->text('remarks')->nullable();
            $table->foreignId('adjusted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('adjusted_at');
            $table->timestamps();
            // No soft deletes — immutable audit log

            $table->index(['employee_id', 'leave_type_id']);
            $table->index('adjusted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_adjustments');
    }
};
