<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blocked sends were previously invisible in the log — nothing was written
 * for a send the gate cancelled. This lets "Recent Emails" show SKIPPED rows
 * with a reason instead of silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('skip_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropColumn('skip_reason');
        });
    }
};
