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
        Schema::create('warning_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warning_letter_id')->constrained('warning_letters')->cascadeOnDelete();
            $table->foreignId('acknowledged_by')->constrained('users')->cascadeOnDelete();
            $table->text('acknowledgement_text')->nullable();
            $table->timestamp('acknowledged_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('warning_letter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warning_acknowledgements');
    }
};
