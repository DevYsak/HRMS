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
        Schema::table('onboarding_tasks', function (Blueprint $table) {
            $table->enum('status', ['pending', 'in_progress', 'completed', 'overdue', 'blocked'])
                ->default('pending')
                ->after('sort_order');
            $table->string('blocked_reason')->nullable()->after('status');
            $table->string('auto_trigger')->nullable()->after('blocked_reason');
            $table->foreignId('template_task_id')
                ->nullable()
                ->after('auto_trigger')
                ->constrained('onboarding_template_tasks')
                ->nullOnDelete();

            $table->index(['employee_id', 'phase', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_tasks', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'phase', 'status']);
            $table->dropConstrainedForeignId('template_task_id');
            $table->dropColumn(['status', 'blocked_reason', 'auto_trigger']);
        });
    }
};
