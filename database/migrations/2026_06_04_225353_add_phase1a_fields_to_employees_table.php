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
        Schema::table('employees', function (Blueprint $table) {
            // Feature 1 — Dynamic Employment Type (FK replaces string column)
            $table->foreignId('employment_type_id')
                ->nullable()
                ->after('employment_type')
                ->constrained('employment_types')
                ->nullOnDelete();

            // Feature 2 — Dynamic Work Mode
            $table->foreignId('work_mode_id')
                ->nullable()
                ->after('employment_type_id')
                ->constrained('work_modes')
                ->nullOnDelete();

            // Feature 3 — Dynamic Salary Cycle (FK replaces string column)
            $table->foreignId('salary_cycle_id')
                ->nullable()
                ->after('salary_cycle')
                ->constrained('salary_cycles')
                ->nullOnDelete();

            // Feature 4 — Lifecycle date fields
            $table->date('resignation_date')->nullable()->after('probation_hr_approved_at');
            $table->date('termination_date')->nullable()->after('resignation_date');
            $table->date('notice_period_end_date')->nullable()->after('termination_date');
            $table->timestamp('absconded_at')->nullable()->after('notice_period_end_date');
            $table->timestamp('confirmed_at')->nullable()->after('absconded_at');
            $table->timestamp('archived_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employment_type_id');
            $table->dropConstrainedForeignId('work_mode_id');
            $table->dropConstrainedForeignId('salary_cycle_id');
            $table->dropColumn([
                'resignation_date', 'termination_date', 'notice_period_end_date',
                'absconded_at', 'confirmed_at', 'archived_at',
            ]);
        });
    }
};
