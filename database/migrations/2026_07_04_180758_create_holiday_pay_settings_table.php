<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton settings row (same pattern as attendance_settings) governing
 * which Holiday Work pay types are offered and how each is calculated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_pay_settings', function (Blueprint $table) {
            $table->id();
            // Which pay types employees may choose when requesting to work a holiday.
            $table->json('allowed_pay_types')->nullable();
            $table->string('default_pay_type', 20)->default('overtime');

            $table->decimal('double_pay_multiplier', 4, 2)->default(2.00);
            $table->decimal('comp_off_days_per_holiday', 4, 2)->default(1.00);
            $table->decimal('extra_leave_days_per_holiday', 4, 2)->default(1.00);
            $table->decimal('half_day_comp_off_days', 4, 2)->default(0.50);
            $table->decimal('ot_rate_per_hour', 8, 2)->nullable(); // null = use OvertimeService default

            $table->boolean('auto_approve_after_manager')->default(true);
            $table->text('policy_notes')->nullable(); // free-text "custom company policy"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_pay_settings');
    }
};
