<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR "apply on behalf" (Phase 2D): who actually submitted a request when it
 * was not the employee, and HR's internal note — never shown to the
 * employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('applied_by_user_id')->nullable()->after('employee_id')->constrained('users')->nullOnDelete();
            $table->text('hr_internal_note')->nullable()->after('hr_remark');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_by_user_id');
            $table->dropColumn('hr_internal_note');
        });
    }
};
