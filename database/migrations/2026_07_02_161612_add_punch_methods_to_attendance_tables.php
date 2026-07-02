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
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('check_in_method', 20)->nullable()->after('check_in_user_agent');
            $table->string('check_out_method', 20)->nullable()->after('check_out_user_agent');
        });

        Schema::table('attendance_daily_summaries', function (Blueprint $table) {
            $table->string('first_punch_method', 20)->nullable()->after('last_punch');
            $table->string('last_punch_method', 20)->nullable()->after('first_punch_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['check_in_method', 'check_out_method']);
        });

        Schema::table('attendance_daily_summaries', function (Blueprint $table) {
            $table->dropColumn(['first_punch_method', 'last_punch_method']);
        });
    }
};
