<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditional default-leave rules. When an employee is created, the most
 * specific matching policy per leave type sets the initial allocation; if none
 * matches, the leave type's uniform annual_allocation_days is the fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_allocation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();

            // Conditions (all nullable — null = "applies to anyone").
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gender')->nullable();
            $table->string('role')->nullable();                 // UserRole value, e.g. 'manager'
            $table->unsignedInteger('min_service_months')->default(0);
            $table->boolean('requires_probation_complete')->default(false);

            $table->decimal('allocated_days', 6, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_allocation_policies');
    }
};
