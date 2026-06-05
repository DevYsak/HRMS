<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approval_rules', function (Blueprint $table) {
            $table->id();
            // null = applies to all leave types
            $table->foreignId('leave_type_id')->nullable()->constrained()->nullOnDelete();
            // null = applies to all departments
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('level')->default(1); // 1 = first approver, 2 = second
            $table->enum('approver_type', [
                'manager', 'department_head', 'hr_admin', 'specific_user', 'super_admin',
            ]);
            // Only set when approver_type = 'specific_user'
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['leave_type_id', 'department_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approval_rules');
    }
};
