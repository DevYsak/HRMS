<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password lifecycle columns.
 *
 * The system could issue a credential but never require anything of it
 * afterwards: no way to mark a password as temporary, no record of when it
 * last changed, and so no way to tell a fresh account from one that had been
 * sitting on its emailed password for a year.
 *
 * All three are nullable/defaulted so existing rows are untouched — an account
 * that has never been flagged simply behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set when a credential is issued on someone's behalf (HR creation,
            // HR reset, biometric onboarding). Cleared the moment they choose
            // their own password.
            $table->boolean('must_change_password')->default(false)->after('password');

            // Null means "never changed since the account was created" — which
            // is exactly the population a rotation policy needs to find.
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');

            $table->timestamp('last_login_at')->nullable()->after('password_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at', 'last_login_at']);
        });
    }
};
