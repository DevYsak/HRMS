<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a shift a stable identity and let HR nominate a company default.
 *
 * Purely additive — two nullable/defaulted columns, no data rewritten and no
 * column dropped, so it is safe to run on production and reversible.
 *
 * `code` exists because shift identity was previously carried by the display
 * name alone. The employee importer creates master data from the free-text
 * label in HR's sheet, so "IT Shift" and "10.30 AM to 7.30 PM" became two rows
 * describing the same 10:30-19:30 window. A code gives the importer something
 * stable to match on (see Priority 4).
 *
 * `is_default` replaces an implicit fallback: ShiftResolver used to reach for
 * ShiftSetting::query()->first() when an employee had no shift, silently
 * scoring them against whichever row happened to be first. Which shift covers
 * an unassigned employee is a policy decision, so HR states it explicitly or
 * it does not happen at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_settings', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique()->after('name');
            $table->boolean('is_default')->default(false)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('shift_settings', function (Blueprint $table) {
            $table->dropColumn(['code', 'is_default']);
        });
    }
};
