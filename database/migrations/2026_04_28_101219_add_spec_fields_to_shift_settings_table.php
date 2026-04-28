<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('grace_minutes')->default(5)->after('break_duration');
            $table->decimal('standard_hours', 4, 2)->default(9.00)->after('grace_minutes');
            $table->decimal('ot_threshold_hours', 4, 2)->default(9.00)->after('standard_hours');
        });
    }

    public function down(): void
    {
        Schema::table('shift_settings', function (Blueprint $table) {
            $table->dropColumn(['grace_minutes', 'standard_hours', 'ot_threshold_hours']);
        });
    }
};
