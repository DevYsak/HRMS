<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the manager was told about this late arrival (spec §7
 * check-late-arrivals: confirm late flags per shift, notify the manager
 * in-app). Two runs a day (10:45 IT, 13:15 UK) must each report a late day
 * once, never twice. Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('late_notified_at')->nullable()->after('late_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('late_notified_at');
        });
    }
};
