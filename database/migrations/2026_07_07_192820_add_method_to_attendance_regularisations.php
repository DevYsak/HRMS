<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the punch method (face / id_card / …) the employee declares for the
     * corrected check-in and check-out, so an approved regularisation can write
     * a proper AttendancePunch into the journey — not just fix the summary times.
     */
    public function up(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->string('check_in_method')->nullable()->after('requested_check_in');
            $table->string('check_out_method')->nullable()->after('requested_check_out');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->dropColumn(['check_in_method', 'check_out_method']);
        });
    }
};
