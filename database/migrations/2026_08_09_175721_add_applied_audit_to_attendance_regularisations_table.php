<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate "who reviewed" from "who applied" on a regularisation.
 *
 * The table already records reviewer_id / reviewed_at, but a request can now
 * reach its applied state by two routes — the full manager → HR → admin chain,
 * or HR's fast-path — and those columns cannot say which happened or who
 * pressed the button that actually rewrote the attendance.
 *
 * Purely additive: three nullable columns, no data rewritten, no column
 * dropped or narrowed. Safe on production and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->foreignId('applied_by')->nullable()->after('reviewer_comment')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable()->after('applied_by');
            // 'admin_chain' or 'hr_fast_path' — which route reached the change.
            $table->string('applied_via', 20)->nullable()->after('applied_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_by');
            $table->dropColumn(['applied_at', 'applied_via']);
        });
    }
};
