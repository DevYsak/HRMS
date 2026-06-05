<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ot_windows', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('reason')->nullable();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ot_windows');
    }
};
