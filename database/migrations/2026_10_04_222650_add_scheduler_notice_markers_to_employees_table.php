<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sent-once markers for scheduler notices about an employee (spec §7):
 * onboarding / offboarding completion and the 30-day new-hire check-in.
 * Kept on the employee, not inferred from the notifications table, which is
 * pruned after 90 days and can be cleared by its recipients. Additive,
 * nullable — existing rows are treated as "not yet sent", and the jobs
 * backfill the marker from any notification row already on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_notified_at')->nullable();
            $table->timestamp('offboarding_completed_notified_at')->nullable();
            $table->timestamp('newhire_checkin_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['onboarding_completed_notified_at', 'offboarding_completed_notified_at', 'newhire_checkin_notified_at']);
        });
    }
};
