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
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            // Type of correction: a punch fix (default) or marking the day as
            // half-day. requested_check_in/out are already nullable so a
            // half-day request needn't carry times.
            $table->string('regularisation_type', 20)->default('punch')->after('work_date');
            $table->string('half_day_period', 10)->nullable()->after('regularisation_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->dropColumn(['regularisation_type', 'half_day_period']);
        });
    }
};
