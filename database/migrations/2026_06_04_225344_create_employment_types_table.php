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
        Schema::create('employment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('probation_days')->default(90);
            $table->unsignedSmallInteger('notice_period_days')->default(30);
            $table->boolean('leave_eligible')->default(true);
            $table->boolean('payroll_eligible')->default(true);
            $table->boolean('ot_eligible')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_types');
    }
};
