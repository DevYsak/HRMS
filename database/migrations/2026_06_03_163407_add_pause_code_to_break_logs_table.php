<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('break_logs', function (Blueprint $table) {
            $table->string('pause_code', 20)->nullable()->after('duration_minutes')
                ->comment('References pause_codes.code — reason for the break');
        });
    }

    public function down(): void
    {
        Schema::table('break_logs', function (Blueprint $table) {
            $table->dropColumn('pause_code');
        });
    }
};
