<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_payment_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->enum('from_status', ['paid', 'unpaid']);
            $table->enum('to_status', ['paid', 'unpaid']);
            $table->text('reason');
            $table->string('stage', 30)->default('approval'); // approval | post_approval
            $table->timestamps();
            // No soft deletes — audit logs are immutable

            $table->index('leave_request_id');
            $table->index('changed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_payment_audit_logs');
    }
};
