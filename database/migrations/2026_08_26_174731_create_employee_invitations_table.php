<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login invitations issued by HR.
 *
 * Employee import creates records; it does not hand out credentials. Somebody
 * has to look at an imported row, confirm it is a real person with a real work
 * address, and decide to let them in. This table is that decision, and its
 * history: who invited whom, when it expires, whether it was ever accepted.
 *
 * The token is stored as a SHA-256 hash. A readable token here would be a
 * password-equivalent sitting in a database that HR staff can query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            // The address the invitation actually went to, kept even if the
            // employee's email changes later — otherwise the audit trail
            // silently rewrites where credentials were sent.
            $table->string('sent_to');

            $table->string('token_hash', 64)->unique();

            // dateTime rather than timestamp: MySQL allows only one TIMESTAMP
            // column without an explicit default, so a second non-null one is
            // rejected outright under a strict sql_mode.
            $table->dateTime('invited_at');
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();

            // Set when a resend supersedes this invitation. A revoked token is
            // dead even if it has not expired yet.
            $table->dateTime('revoked_at')->nullable();

            $table->unsignedSmallInteger('resend_count')->default(0);

            $table->timestamps();

            $table->index(['employee_id', 'accepted_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_invitations');
    }
};
