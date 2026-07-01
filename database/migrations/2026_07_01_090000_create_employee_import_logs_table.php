<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit record for each bulk employee-import run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename')->nullable();
            $table->string('mode')->default('skip');       // skip | update
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('errors')->nullable();            // [{row, messages[]}]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_logs');
    }
};
