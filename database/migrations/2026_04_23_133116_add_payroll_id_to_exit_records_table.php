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
        Schema::table('exit_records', function (Blueprint $table) {
            $table->foreignId('payroll_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exit_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_id');
        });
    }
};
