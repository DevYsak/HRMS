<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the thin public_holidays table (date/name/country) into a full
 * Holiday Management model: types, scoping (branch/department/employees),
 * pay flags, recurrence, colour and archive — all additive and nullable so
 * every existing consumer (PublicHoliday::isHoliday, attendance/leave/report
 * queries) keeps working unchanged.
 *
 * The (date,country) unique is dropped because scoping now legitimately
 * allows multiple holidays on the same date (e.g. a national holiday plus a
 * branch-specific one); the existing (country,date) index remains for lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->string('holiday_type', 30)->default('national')->after('name'); // national|state|festival|company|optional|branch
            $table->string('category', 60)->nullable()->after('holiday_type');
            $table->string('color', 20)->nullable()->after('category');
            $table->text('description')->nullable()->after('color');

            $table->boolean('is_paid')->default(true)->after('description');
            $table->boolean('is_optional')->default(false)->after('is_paid');
            $table->boolean('is_recurring')->default(false)->after('is_optional'); // repeats yearly (same month/day)
            $table->boolean('is_active')->default(true)->after('is_recurring');     // false = archived

            // Scope: null on all three = applies company-wide for the country.
            $table->foreignId('office_id')->nullable()->after('is_active')->constrained('offices')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('office_id')->constrained('departments')->nullOnDelete();
            $table->json('applicable_employee_ids')->nullable()->after('department_id'); // specific employees, null = not restricted

            $table->foreignId('created_by')->nullable()->after('applicable_employee_ids')->constrained('users')->nullOnDelete();

            $table->index(['holiday_type', 'is_active']);
            $table->index('office_id');
            $table->index('department_id');
        });

        // Scoping permits multiple holidays per date; drop the (date,country) unique.
        Schema::table('public_holidays', function (Blueprint $table) {
            try {
                $table->dropUnique('public_holidays_date_country_unique');
            } catch (Throwable) {
                // Constraint already absent (fresh/alternate schema) — safe to ignore.
            }
        });
    }

    public function down(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'holiday_type', 'category', 'color', 'description',
                'is_paid', 'is_optional', 'is_recurring', 'is_active',
                'applicable_employee_ids',
            ]);
            $table->unique(['date', 'country'], 'public_holidays_date_country_unique');
        });
    }
};
