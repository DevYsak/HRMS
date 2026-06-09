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
        Schema::create('nexflow_ot_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('sync_date');
            $table->decimal('net_hours', 5, 2)->default(0);
            $table->decimal('shift_threshold', 5, 2)->default(9.00);
            $table->decimal('ot_hours_detected', 5, 2)->default(0);
            $table->foreignId('ot_request_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('action', ['created', 'auto_approved', 'skipped', 'duplicate'])->default('created');
            $table->string('skip_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'sync_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nexflow_ot_sync_logs');
    }
};
