<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department-wise notification rules: for an event (or one stage of it,
 * "FQCN@stage") and a department, whether it is on for that department's
 * people, who else receives it, who never does, and whether only the listed
 * people receive it. Applied by NotificationRecipientPolicy to the employee
 * the notification is about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_department_rules', function (Blueprint $table) {
            $table->id();
            $table->string('notification_key', 191);
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('replace_defaults')->default(false);
            $table->json('include_roles')->nullable();
            $table->json('include_user_ids')->nullable();
            $table->json('exclude_roles')->nullable();
            $table->json('exclude_user_ids')->nullable();
            $table->timestamps();

            // Named: the default name is longer than MySQL's 64 characters.
            $table->unique(['notification_key', 'department_id'], 'notif_dept_rules_key_dept_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_department_rules');
    }
};
