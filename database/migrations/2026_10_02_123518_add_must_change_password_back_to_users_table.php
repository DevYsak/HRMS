<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restores users.must_change_password, this time with something enforcing it.
 *
 * The column was dropped in 2026_08_25 because nothing read it. It now gates
 * every authenticated route through EnsurePasswordChanged: an account issued a
 * temporary credential can reach nothing but the "Set your password" page
 * until its owner has chosen their own.
 *
 * Defaults to false, so no existing account is forced into a reset by this
 * migration. Guarded both ways because environments differ on whether the
 * earlier drop ever ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
