<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            // Drop the incorrect single-column unique on date
            $table->dropUnique(['date']);
            // Add the correct composite unique on (date, country)
            $table->unique(['date', 'country'], 'public_holidays_date_country_unique');
        });
    }

    public function down(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->dropUnique('public_holidays_date_country_unique');
            $table->unique('date');
        });
    }
};
