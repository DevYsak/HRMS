<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The expiry date HR was last told about (spec §7 check-document-expiry).
 * The daily job re-sent the same "expiring in 30 days" digest every day; with
 * this each expiry date is announced once. Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->date('expiry_notified_for')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('expiry_notified_for');
        });
    }
};
