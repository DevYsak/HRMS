<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('escalated_to')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->default('No response within 24 hours.');
            $table->timestamp('escalated_at');
            $table->boolean('resolved')->default(false);
            $table->timestamps();

            $table->index(['leave_request_id', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_escalations');
    }
};
