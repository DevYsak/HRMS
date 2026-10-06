<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A substitute holiday (e.g. a bank holiday moved off a weekend) points at
 * the holiday it replaces. Nullable and additive; the substitute itself is
 * an ordinary holiday row with holiday_type 'substitute'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('public_holidays', 'substitute_for_id')) {
            Schema::table('public_holidays', function (Blueprint $table) {
                $table->foreignId('substitute_for_id')->nullable()->after('holiday_type')
                    ->constrained('public_holidays')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('public_holidays', 'substitute_for_id')) {
            Schema::table('public_holidays', function (Blueprint $table) {
                $table->dropConstrainedForeignId('substitute_for_id');
            });
        }
    }
};
