<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave V2 — whether a leave type is offered for new requests.
 *
 * Additive. 'active' (the default, so every existing type keeps behaving as
 * it does), 'historical' (kept readable in old requests, history and audit,
 * never requested or given a new balance again) or 'inactive'. Referenced
 * types are marked historical rather than deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('availability', 20)->default('active')->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropIndex(['availability']);
            $table->dropColumn('availability');
        });
    }
};
