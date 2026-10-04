<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a pending OT request was escalated to HR (spec §7: OT requests pending
 * more than 24 hours notify HR Admin). The hourly job re-sent the same
 * escalation every hour for as long as a request stayed pending; this marker
 * makes it once per request. Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ot_requests', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ot_requests', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
