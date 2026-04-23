<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('december_mandatory_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->date('date');
            $table->string('description')->default('Mandatory December Leave');
            $table->timestamps();

            $table->unique(['year', 'date']);
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('december_mandatory_days');
    }
};
