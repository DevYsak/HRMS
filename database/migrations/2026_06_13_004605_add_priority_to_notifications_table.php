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
        Schema::table('notifications', function (Blueprint $table) {
            // high | normal | low — auto-populated from the notification's
            // data['priority'] by the DatabaseNotification::creating listener.
            $table->string('priority', 10)->default('normal')->after('data')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropColumn('priority');
        });
    }
};
