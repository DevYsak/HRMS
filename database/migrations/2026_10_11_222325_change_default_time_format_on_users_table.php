<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New accounts start on the 12-hour clock (AM/PM). Only the column default
 * changes: every existing user keeps the time format already saved for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('time_format')->default('h:i A')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('time_format')->default('H:i')->change();
        });
    }
};
