<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            // Device serial number — used to authenticate ADMS push requests
            $table->string('serial_number', 50)->nullable()->unique()->after('name');
            // Last ATTLOG timestamp received via ADMS (device only sends records after this)
            $table->unsignedBigInteger('adms_stamp')->default(0)->after('last_sync_count');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropUnique(['serial_number']);
            $table->dropColumn(['serial_number', 'adms_stamp']);
        });
    }
};
