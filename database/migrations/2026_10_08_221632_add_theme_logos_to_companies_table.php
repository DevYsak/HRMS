<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branding per theme: `logo` stays the light-mode wordmark; `logo_dark` is
 * the wordmark for dark surfaces, and the two marks are the square icon the
 * sidebar shows when it is folded to a rail. All optional — an empty slot
 * falls back to the bundled Nexcore artwork.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_dark')->nullable()->after('logo');
            $table->string('logo_mark')->nullable()->after('logo_dark');
            $table->string('logo_mark_dark')->nullable()->after('logo_mark');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['logo_dark', 'logo_mark', 'logo_mark_dark']);
        });
    }
};
