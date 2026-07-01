<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-controlled per-notification delivery settings.
 *
 * One row per notifiable event (a Notification class or a gated Mailable),
 * keyed by the fully-qualified class name. The NotificationSending /
 * MessageSending gates read these to decide whether each channel fires.
 * Lookups are fail-open: a missing row means "send" (existing behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();           // FQCN, e.g. App\Notifications\LeaveRequestNotification
            $table->string('label');                   // Human label, e.g. "Leave Request"
            $table->string('group')->default('General')->index();
            $table->string('description')->nullable();
            $table->boolean('mail_enabled')->default(true);
            $table->boolean('database_enabled')->default(true);
            $table->boolean('is_automatic')->default(true); // false = suppress automatic email (manual only)
            $table->string('custom_subject')->nullable();
            $table->text('custom_body')->nullable();   // overrides the primary mail body line
            $table->boolean('is_system')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
