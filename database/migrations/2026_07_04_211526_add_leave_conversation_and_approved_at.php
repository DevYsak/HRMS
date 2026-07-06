<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A two-way conversation thread on a leave request (employee ↔ manager/HR),
 * each message optionally carrying one attachment, plus an approved_at stamp
 * used to auto-purge attachments 30 days after approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->timestamps();

            $table->index('leave_request_id');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
        Schema::dropIfExists('leave_messages');
    }
};
