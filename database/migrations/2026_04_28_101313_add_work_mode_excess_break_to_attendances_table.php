<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Work mode selected by employee at clock-in (spec §3.2)
            $table->enum('work_mode', ['office', 'wfh'])->default('office')->after('status');
            // Excess break flag — set by scheduled job when total break > 60 min (spec §3.2)
            $table->boolean('excess_break_flag')->default(false)->after('missing_checkout');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['work_mode', 'excess_break_flag']);
        });
    }
};
