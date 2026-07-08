<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The biometric engine pairs each punch's real IN/OUT direction. Storing it
     * lets HRMS build sessions from the truth instead of guessing by alternation
     * (which mis-pairs when a return punch lands close to an exit punch).
     */
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->string('direction', 3)->nullable()->after('method'); // in | out
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
