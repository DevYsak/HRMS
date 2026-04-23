<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Replace GPS-dependent geofence flag with IP-based logging
            $table->string('check_in_ip', 45)->nullable()->after('check_out_lng');
            $table->string('check_out_ip', 45)->nullable()->after('check_in_ip');

            // Break tracking
            $table->dateTime('break_start')->nullable()->after('check_out_ip');
            $table->dateTime('break_end')->nullable()->after('break_start');
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('break_end');

            // Late tracking
            $table->boolean('is_late')->default(false)->after('break_minutes');
            $table->unsignedSmallInteger('late_minutes')->default(0)->after('is_late');

            // Missing clock-out flag (set by scheduled job)
            $table->boolean('missing_checkout')->default(false)->after('late_minutes');

            // Notes (for regularisation context)
            $table->text('notes')->nullable()->after('missing_checkout');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_ip', 'check_out_ip',
                'break_start', 'break_end', 'break_minutes',
                'is_late', 'late_minutes',
                'missing_checkout', 'notes',
            ]);
        });
    }
};
