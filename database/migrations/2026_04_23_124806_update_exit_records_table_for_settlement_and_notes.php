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
            $table->renameColumn('exit_interview_notes', 'interview_notes');
            $table->decimal('final_settlement_amount', 12, 2)->nullable()->after('final_settlement_done');
        });
    }

    public function down(): void
    {
        Schema::table('exit_records', function (Blueprint $table) {
            $table->renameColumn('interview_notes', 'exit_interview_notes');
            $table->dropColumn('final_settlement_amount');
        });
    }
};
