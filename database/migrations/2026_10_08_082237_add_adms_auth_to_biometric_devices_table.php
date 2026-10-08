<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device authentication for the ADMS push endpoint (/iclock).
 *
 * Knowing a device serial number was enough to write attendance. A device must
 * now push from an address it is registered for, and — when HR issues one —
 * present its token. Only the token's hash is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('biometric_devices', 'adms_allowed_ips')) {
                // Comma-separated IPs / CIDR ranges the device pushes from.
                $table->string('adms_allowed_ips', 500)->nullable()->after('adms_stamp');
            }
            if (! Schema::hasColumn('biometric_devices', 'adms_token_hash')) {
                $table->string('adms_token_hash', 64)->nullable()->after('adms_allowed_ips');
            }
        });
    }

    public function down(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropColumn(['adms_allowed_ips', 'adms_token_hash']);
        });
    }
};
