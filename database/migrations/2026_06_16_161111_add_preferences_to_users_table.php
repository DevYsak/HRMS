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
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->default('Asia/Kolkata')->after('email');
            $table->string('date_format')->default('d M Y')->after('timezone');
            $table->string('time_format')->default('H:i')->after('date_format');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'date_format', 'time_format']);
        });
    }
};
