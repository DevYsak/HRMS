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
        Schema::table('departments', function (Blueprint $table): void {
            $table->enum('default_ot_source', ['biometric', 'manual', 'nexflow', 'hybrid'])
                ->default('biometric')
                ->after('head_id');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('default_ot_source');
        });
    }
};
