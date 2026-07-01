<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen attendances.work_mode from enum('office','wfh') to a string so the one
 * attendance module can record every mode (Office, WFH, Hybrid, Client Visit,
 * On Duty, Training, Business Travel — see App\Enums\AttendanceMode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('work_mode', 30)->default('office')->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->enum('work_mode', ['office', 'wfh'])->default('office')->change();
        });
    }
};
