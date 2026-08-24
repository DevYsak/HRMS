<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leave year, and the policy that decides what a year is worth.
 *
 * Two things were missing and neither could be faked with the existing
 * columns.
 *
 * A leave year was a `smallint` holding 2026, which can only ever mean
 * 1 January to 31 December. Conexus runs 1 July to 30 June, so no balance
 * could describe the period it actually belonged to. `leave_years` gives every
 * balance a real start and end date; the old `year` column stays for the
 * moment so existing queries keep working while callers migrate over.
 *
 * Entitlement was a single number per leave type — "12 days" — with no record
 * of how it was arrived at. UK entitlement is 5.6 weeks of the employee's own
 * working week, optionally enhanced by contract, and bank holidays may sit
 * inside that figure or on top of it depending on what was agreed. A flat
 * number cannot answer "is that statutory or contractual?", which is exactly
 * what anyone auditing holiday pay asks first.
 *
 * Nothing here changes an existing balance. Both tables are new, and the
 * employee link is nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Leave policy ──────────────────────────────────────────────────
        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();

            // 5.6 is the UK statutory minimum. Held as weeks, not days, because
            // days depend on the employee's working week and weeks do not.
            $table->decimal('statutory_weeks', 4, 2)->default(5.60);

            // Anything the contract adds on top. Kept separate so the two can
            // always be reported apart, which is the whole point.
            $table->decimal('contractual_additional_weeks', 4, 2)->default(0);

            // Whether bank holidays come out of the entitlement above, or are
            // paid on top of it. There is no correct default in law — it is
            // whatever the contract says — so this is explicit configuration.
            $table->enum('bank_holiday_treatment', ['included', 'additional'])->default('additional');

            // Statutory carry-over is limited and situational; a company may
            // allow more. Zero means none.
            $table->decimal('max_carry_over_days', 5, 2)->default(0);
            $table->unsignedSmallInteger('carry_over_expiry_months')->nullable();

            // Irregular-hours and part-year workers accrue 12.07% of hours
            // worked for leave years starting on or after 1 April 2024.
            $table->decimal('irregular_accrual_rate', 6, 4)->default(0.1207);

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Leave year ────────────────────────────────────────────────────
        Schema::create('leave_years', function (Blueprint $table) {
            $table->id();

            // A label people recognise: "2026/27" for 1 Jul 2026 – 30 Jun 2027.
            $table->string('label', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->unique(['starts_on', 'ends_on']);
            $table->index('starts_on');
        });

        // ── Company configuration ─────────────────────────────────────────
        Schema::table('companies', function (Blueprint $table) {
            // Conexus: 1 July. Stored as month/day so future years generate
            // themselves rather than needing a row created by hand each time.
            $table->unsignedTinyInteger('leave_year_start_month')->default(7)->after('holiday_calendar');
            $table->unsignedTinyInteger('leave_year_start_day')->default(1)->after('leave_year_start_month');
        });

        // ── Employee link ─────────────────────────────────────────────────
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('leave_policy_id')->nullable()->after('holiday_calendar')->constrained('leave_policies')->nullOnDelete();
        });

        // ── Balances belong to a real period ──────────────────────────────
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->foreignId('leave_year_id')->nullable()->after('year')->constrained('leave_years')->nullOnDelete();
        });

        // The UK default, matching what the company already follows. Created
        // here rather than in a seeder so a fresh deploy is correct without
        // anyone remembering to run something.
        DB::table('leave_policies')->insert([
            'name' => 'UK Standard',
            'description' => 'UK statutory minimum of 5.6 weeks. Bank holidays paid in addition to entitlement.',
            'statutory_weeks' => 5.60,
            'contractual_additional_weeks' => 0,
            'bank_holiday_treatment' => 'additional',
            'max_carry_over_days' => 0,
            'irregular_accrual_rate' => 0.1207,
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('leave_balances', fn (Blueprint $table) => $table->dropConstrainedForeignId('leave_year_id'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropConstrainedForeignId('leave_policy_id'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['leave_year_start_month', 'leave_year_start_day']));
        Schema::dropIfExists('leave_years');
        Schema::dropIfExists('leave_policies');
    }
};
