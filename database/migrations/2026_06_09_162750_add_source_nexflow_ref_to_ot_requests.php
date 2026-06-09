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
        Schema::table('ot_requests', function (Blueprint $table): void {
            $table->enum('source', ['manual', 'nexflow', 'biometric'])
                ->default('manual')
                ->after('status');
            $table->string('nexflow_ref', 100)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('ot_requests', function (Blueprint $table): void {
            $table->dropColumn(['source', 'nexflow_ref']);
        });
    }
};
