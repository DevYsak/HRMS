<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_encashments', function (Blueprint $table) {
            // Tracks which leave year the encashed days originate from (must be previous year per policy)
            $table->unsignedSmallInteger('source_leave_year')->nullable()->after('payout_month');
        });
    }

    public function down(): void
    {
        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->dropColumn('source_leave_year');
        });
    }
};
