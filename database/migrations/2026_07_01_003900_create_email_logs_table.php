<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing email audit trail. Written by the Mail MessageSending / MessageSent
 * listeners so admins can see what was sent, to whom, and whether it failed —
 * independent of the queue's failed_jobs table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('notification_key')->nullable()->index(); // FQCN if traceable
            $table->string('to_email')->nullable()->index();
            $table->string('to_name')->nullable();
            $table->string('subject')->nullable();
            $table->string('status')->default('sending')->index();   // sending|sent|failed
            $table->text('error')->nullable();
            $table->nullableMorphs('notifiable');                    // recipient model, if known
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
