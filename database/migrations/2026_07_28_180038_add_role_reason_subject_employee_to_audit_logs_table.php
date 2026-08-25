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
        Schema::table('audit_logs', function (Blueprint $table) {
            // Nullable, backward compatible — the other 9 non-payroll observers
            // keep working unchanged and simply leave these null.
            $table->string('role')->nullable()->after('user_id');
            $table->text('reason')->nullable()->after('new_values');
            // No FK constraint, matching how auditable_id already works (a plain
            // reference column, not a hard relation) — this identifies which
            // employee an event was ABOUT, distinct from auditable_id which
            // identifies the mutated row (e.g. a payslip, not its employee).
            $table->unsignedBigInteger('subject_employee_id')->nullable()->after('auditable_id');

            $table->index('subject_employee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['subject_employee_id']);
            $table->dropColumn(['role', 'reason', 'subject_employee_id']);
        });
    }
};
