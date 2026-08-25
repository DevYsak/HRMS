<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the holiday calendar an explicit setting instead of a guess.
 *
 * Which calendar an employee followed was inferred from whether their shift
 * NAME contained the letters "UK". The company's actual UK Operations shift is
 * called "1PM to 10PM", so every UK employee was silently resolved to the
 * Indian calendar — wrong public holidays, and attendance scored against the
 * wrong non-working days. A naming coincidence is not a business rule.
 *
 * Resolution becomes, in order:
 *   1. employees.holiday_calendar   — per-employee override, normally null
 *   2. offices.holiday_calendar     — per-site override, normally null
 *   3. companies.holiday_calendar   — the company default
 *
 * Note that `offices.country` is deliberately NOT consulted. An office's
 * country is where a desk is, not a statement about holiday entitlement — a
 * UK-policy company with staff in Bangalore still owes them the calendar their
 * contract names. Inferring policy from an address is the same mistake as
 * inferring it from a shift name, so offices get their own explicit column and
 * it stays null until somebody sets it on purpose.
 *
 * Conexus follows UK employment policy, so the company default is set to UK
 * here. The Indian holidays stay in the table; they simply belong to nobody
 * unless an office or an employee is explicitly put on that calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('holiday_calendar', 5)->default('UK')->after('country');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->string('holiday_calendar', 5)->nullable()->after('country');
        });

        Schema::table('employees', function (Blueprint $table) {
            // Null means "inherit" — an override exists for the individual
            // cases (a UK employee sitting in an India office, say) without
            // forcing HR to set it on all 28 records.
            $table->string('holiday_calendar', 5)->nullable()->after('office_id');
        });

        // The existing company row predates the column and would otherwise keep
        // the column default only by luck of insertion order.
        DB::table('companies')->update(['holiday_calendar' => 'UK']);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('holiday_calendar');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('holiday_calendar');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('holiday_calendar');
        });
    }
};
