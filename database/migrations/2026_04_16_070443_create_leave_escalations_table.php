<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
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
            // MySQL rejects literal defaults on TEXT columns; a parenthesised
            // expression default is accepted (MySQL 8.0.13+, MariaDB 10.2+).
            $table->text('reason')->default(new Expression("('No response within 24 hours.')"));
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
