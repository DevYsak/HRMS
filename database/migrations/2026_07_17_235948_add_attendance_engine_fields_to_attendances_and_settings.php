<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Immutable snapshot of the original punch taken the first time a day
            // is regularised — the raw punch is never overwritten, only preserved.
            $table->dateTime('original_check_in')->nullable()->after('check_out');
            $table->dateTime('original_check_out')->nullable()->after('original_check_in');

            // System-generated check-out (auto punch-out engine). Kept regularizable.
            $table->boolean('is_auto_checkout')->default(false)->after('missing_checkout');
            // missing_punchout | ot_auto_close — why the engine closed the day.
            $table->string('auto_checkout_reason', 30)->nullable()->after('is_auto_checkout');
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            // Minutes to wait past shift-end before auto-closing an open day. The
            // OUT itself is stamped at shift-end, not shift-end + buffer.
            $table->unsignedSmallInteger('auto_checkout_buffer_minutes')->default(30)->after('late_grace_period');
            // When an approved-OT day is still open, hold until this clock time
            // (end of the working day) before auto-closing it. DB-driven.
            $table->time('ot_auto_close_time')->default('23:59:00')->after('auto_checkout_buffer_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['original_check_in', 'original_check_out', 'is_auto_checkout', 'auto_checkout_reason']);
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_checkout_buffer_minutes', 'ot_auto_close_time']);
        });
    }
};
