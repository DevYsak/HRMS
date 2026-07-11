<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Claim-lock for multi-HR approval routing (v4 Part 2.3): the first HR to
     * open a pending request claims it; other HRs see "Being handled by …" so
     * the same request is never processed twice. Claims auto-release after
     * 2 hours of inaction via pulse:release-stale-claims.
     */
    private const TABLES = [
        'leave_requests',
        'ot_requests',
        'attendance_regularisations',
        'leave_encashments',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('claimed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('claimed_by');
                $t->dropColumn('claimed_at');
            });
        }
    }
};
