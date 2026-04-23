<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exit_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('last_working_day');
            $table->enum('exit_type', ['resignation', 'termination', 'retirement', 'contract_end', 'other'])->default('resignation');
            $table->text('exit_reason')->nullable();
            $table->boolean('notice_period_served')->default(false);
            $table->boolean('clearance_done')->default(false);
            $table->boolean('final_settlement_done')->default(false);
            $table->boolean('exit_interview_done')->default(false);
            $table->text('exit_interview_notes')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_records');
    }
};
