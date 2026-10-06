<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recipient control, personal preferences and de-duplication for
 * notifications. All additive; nulls / no rows mean today's behaviour.
 *
 * - notification_settings: per event, roles to include / exclude (RBAC role
 *   slugs — not the "relationship" roles of notification_role_settings),
 *   specific users, departments, "apply to all", and "mandatory" (cannot be
 *   muted by the recipient).
 * - notification_preferences: a user muting an optional event's email or
 *   in-app notice.
 * - notification_dispatches: one row per (event, person, reason) already
 *   sent, so reminders and escalations are never repeated. Kept apart from
 *   the notifications table, which is pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_settings', 'include_roles')) {
                $table->json('include_roles')->nullable()->after('is_automatic');
                $table->json('exclude_roles')->nullable()->after('include_roles');
                $table->json('include_user_ids')->nullable()->after('exclude_roles');
                $table->json('department_ids')->nullable()->after('include_user_ids');
                $table->boolean('apply_to_all')->default(false)->after('department_ids');
                $table->boolean('is_mandatory')->default(false)->after('apply_to_all');
            }
        });

        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('notification_key', 191);
                $table->boolean('mail_muted')->default(false);
                $table->boolean('database_muted')->default(false);
                $table->timestamps();
                $table->unique(['user_id', 'notification_key']);
            });
        }

        if (! Schema::hasTable('notification_dispatches')) {
            Schema::create('notification_dispatches', function (Blueprint $table) {
                $table->id();
                $table->string('notification_key', 191);
                $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('dedup_key', 191)->unique();
                $table->timestamp('sent_at');
                $table->index(['notification_key', 'sent_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
        Schema::dropIfExists('notification_preferences');

        Schema::table('notification_settings', function (Blueprint $table) {
            if (Schema::hasColumn('notification_settings', 'include_roles')) {
                $table->dropColumn(['include_roles', 'exclude_roles', 'include_user_ids', 'department_ids', 'apply_to_all', 'is_mandatory']);
            }
        });
    }
};
