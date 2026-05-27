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
        Schema::table('employees', function (Blueprint $table) {
            // Numeric ID enrolled in the biometric device (e.g. "3", "47")
            $table->string('biometric_id', 20)->nullable()->unique()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['biometric_id']);
            $table->dropColumn('biometric_id');
        });
    }
};
