<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-role delivery override for a notification event.
 *
 * notification_settings governs an event as a whole and remains the fallback
 * for every role that has no row here — this table only ever narrows that
 * default for roles an admin has explicitly configured, so an event with no
 * rows behaves exactly as it did before this table existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_role_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_setting_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // 'employee' | 'manager' | 'hr_admin' | 'director' | ...
            $table->boolean('mail_enabled')->default(true);
            $table->boolean('database_enabled')->default(true);
            $table->boolean('is_automatic')->default(true);
            $table->string('custom_subject')->nullable();
            $table->text('custom_body')->nullable();
            $table->timestamps();

            $table->unique(['notification_setting_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_role_settings');
    }
};
