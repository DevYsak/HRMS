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
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('employee_code')->nullable();
            $table->dateTime('punched_at');
            $table->date('punch_date');
            $table->string('method', 20)->nullable();     // face | id_card | fingerprint | ...
            $table->string('verify_raw', 20)->nullable();  // raw device verify code
            $table->string('source', 20)->default('biometric'); // biometric | web | mobile | manual
            $table->string('device_serial', 100)->nullable();
            $table->string('location', 120)->nullable();   // gate/zone when the device provides it
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'punched_at']);
            $table->index(['employee_id', 'punch_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
