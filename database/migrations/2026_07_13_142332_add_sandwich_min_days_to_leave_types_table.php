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
        Schema::table('leave_types', function (Blueprint $table) {
            // Minimum leave span (in calendar days) before the sandwich rule
            // applies. 0 = apply whenever is_sandwich_applicable is on (legacy
            // behaviour). e.g. 3 = only sandwich leaves of 3+ days.
            $table->unsignedTinyInteger('sandwich_min_days')->default(0)->after('is_sandwich_applicable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('sandwich_min_days');
        });
    }
};
