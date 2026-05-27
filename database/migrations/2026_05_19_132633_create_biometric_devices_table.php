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
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address', 45);
            $table->unsignedSmallInteger('port')->default(4370);
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->boolean('is_active')->default(true);

            // Sync tracking
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('last_sync_count')->default(0);

            // Connection health
            $table->timestamp('last_ping_at')->nullable();
            $table->enum('last_ping_status', ['online', 'offline', 'error'])->nullable();
            $table->text('last_ping_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};
