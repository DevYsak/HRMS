<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's working pattern — which is not their shift.
 *
 * A shift says when the working day starts and ends. A working pattern says
 * which days they work and how many hours a week they are contracted for.
 * Two people on the same 1PM–10PM shift can work five days and three days
 * respectively, and their holiday entitlement differs accordingly; two people
 * on different shifts can both work Monday to Friday and be entitled to
 * exactly the same. Neither fact is derivable from the other, which is why
 * these live on the employee rather than on shift_settings.
 *
 * Entitlement needs all three:
 *   - days per week   → 5.6 weeks × days = statutory days
 *   - which weekdays  → whether a bank holiday even falls on a working day
 *   - hours per week  → irregular-hours accrual, and pro-rata by hours
 *
 * Everything is nullable with a sensible regular-full-time default, so
 * existing employees keep behaving as they do today until HR sets a pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // regular      — fixed days each week, the common case
            // irregular_hours — hours vary; accrues at the statutory percentage
            // part_year    — works only part of the year, same accrual method
            $table->enum('working_pattern', ['regular', 'irregular_hours', 'part_year'])
                ->default('regular')
                ->after('leave_policy_id');

            // 5.0 for a Monday-to-Friday employee, 3.0 for someone on three
            // days. Decimal because half-days are a real pattern.
            $table->decimal('working_days_per_week', 3, 1)->nullable()->after('working_pattern');

            $table->decimal('contracted_hours_per_week', 5, 2)->nullable()->after('working_days_per_week');

            // Which weekdays, as ISO numbers (1 = Monday … 7 = Sunday).
            // Needed because a bank holiday falling on a day somebody never
            // works is not deducted from their entitlement.
            $table->json('working_days')->nullable()->after('contracted_hours_per_week');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'working_pattern',
                'working_days_per_week',
                'contracted_hours_per_week',
                'working_days',
            ]);
        });
    }
};
