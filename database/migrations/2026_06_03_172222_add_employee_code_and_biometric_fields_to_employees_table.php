<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Primary biometric matching key — the numeric code enrolled on the device.
            // Attendance is ONLY mapped via this code; never by name or email.
            $table->unsignedSmallInteger('employee_code')->nullable()->unique()->after('biometric_id');

            // Device-level user ID (may differ from employee_code on some ZKTeco firmware).
            $table->string('biometric_user_id', 20)->nullable()->after('employee_code');

            // Which physical device this employee is primarily enrolled on.
            $table->foreignId('biometric_device_id')->nullable()->after('biometric_user_id')
                ->constrained('biometric_devices')->nullOnDelete();

            // pending = not yet synced to device; synced = enrolled; failed = sync error.
            $table->enum('sync_status', ['pending', 'synced', 'failed'])->default('pending')->after('biometric_device_id');

            $table->timestamp('last_biometric_sync_at')->nullable()->after('sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['biometric_user_id', 'sync_status', 'last_biometric_sync_at']);
            $table->dropConstrainedForeignId('biometric_device_id');
            $table->dropUnique(['employee_code']);
            $table->dropColumn('employee_code');
        });
    }
};
