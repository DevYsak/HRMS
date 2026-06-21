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
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('openai');
            $table->text('api_key')->nullable();      // stored encrypted via model cast
            $table->string('model', 64)->nullable();
            $table->string('base_url')->nullable();
            $table->boolean('enabled')->default(false);
            $table->json('allowed_roles')->nullable(); // role values that may use AI features
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
