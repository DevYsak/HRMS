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
        Schema::create('biometric_logs', function (Blueprint $table) {
            $table->id();

            // Source device
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();

            // As reported by the device (numeric string, no FK — device may have unknown users)
            $table->string('device_user_id', 20);

            // Resolved after matching with Employee.biometric_id
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            // The actual punch timestamp from device
            $table->dateTime('punched_at');

            // 0=check_in, 1=check_out, 4=ot_in, 5=ot_out
            $table->enum('punch_type', ['check_in', 'check_out', 'ot_in', 'ot_out', 'unknown'])->default('unknown');

            // 1=fingerprint, 2=pin, 3=card, 4=face, 15=other
            $table->unsignedTinyInteger('verify_type')->default(0);

            // Whether this log has been applied to the attendances table
            $table->boolean('is_processed')->default(false);
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->text('process_error')->nullable();

            $table->timestamps();

            // Prevent the same physical punch from being stored twice
            $table->unique(['device_id', 'device_user_id', 'punched_at']);
            $table->index(['is_processed', 'employee_id']);
            $table->index('punched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_logs');
    }
};
