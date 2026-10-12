<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which punch a regularisation actually corrects: 'in', 'out' or 'both'.
 *
 * requested_check_in / requested_check_out keep holding both times (the
 * untouched side is the existing canonical punch, for display), so approval
 * needs to know which side to change — and must leave the other genuine
 * punch alone.
 *
 * Purely additive: one nullable column, no data rewritten. Existing rows stay
 * null, which means "both" — exactly how they were applied before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->string('corrected_side', 8)->nullable()->after('requested_check_out');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->dropColumn('corrected_side');
        });
    }
};
