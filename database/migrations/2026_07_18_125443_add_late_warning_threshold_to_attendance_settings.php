<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // Monthly late-mark count at which a warning fires (Rule 10) —
            // configurable by HR, no code change needed.
            $table->unsignedTinyInteger('late_warning_threshold')->default(3)->after('late_grace_period');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('late_warning_threshold');
        });
    }
};
