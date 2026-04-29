<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_id');
        });

        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->foreignId('attendance_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_id');
        });

        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->foreignId('attendance_id')->after('employee_id')->constrained()->cascadeOnDelete();
        });
    }
};
