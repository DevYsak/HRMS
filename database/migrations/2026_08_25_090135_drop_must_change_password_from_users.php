<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops users.must_change_password.
 *
 * The column existed to support a forced first-login password change, which
 * was built and then withdrawn. Nothing has read it since; it was still being
 * written on employee creation, employee import and biometric sync, which is
 * how an import failed with "Unknown column 'must_change_password' in 'field
 * list'" against a database where the adding migration had not been applied.
 *
 * A flag nothing enforces is worse than no flag: it survives as a column
 * everyone assumes means something, and it made the schema a prerequisite for
 * code that had no use for it.
 *
 * Guarded both ways so it is safe wherever it lands — some environments
 * already lack the column, which is precisely how the failure surfaced.
 *
 * password_changed_at and last_login_at are deliberately kept. They answer
 * "whose password is stale?" and "who is dormant?" without gating anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }

    /**
     * Restores the column, not the behaviour. Everything defaults to false,
     * which is what every row held once the forced-change flow was removed.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }
};
