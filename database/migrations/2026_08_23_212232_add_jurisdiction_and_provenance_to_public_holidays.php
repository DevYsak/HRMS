<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UK bank holidays are not one calendar.
 *
 * England & Wales, Scotland and Northern Ireland differ: Scotland has 2 January
 * and St Andrew's Day and takes its summer bank holiday in early August rather
 * than late; Northern Ireland adds St Patrick's Day and the Battle of the
 * Boyne. The table could only say "UK", so those could not be represented at
 * all — and a Scottish employee would have been given the English calendar.
 *
 * Existing UK rows are stamped england-and-wales, which is what they are: the
 * eight 2026 dates match GOV.UK's England & Wales list exactly. Indian rows get
 * no jurisdiction, since the concept does not apply.
 *
 * `source` and `updated_by` are here because holiday records are the input to
 * pay and absence decisions, so where a date came from and who last touched it
 * both need to be answerable years later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->string('jurisdiction', 40)->nullable()->after('country');
            $table->string('source', 255)->nullable()->after('description');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();

            $table->index(['country', 'jurisdiction', 'date'], 'ph_country_jurisdiction_date_index');
        });

        DB::table('public_holidays')
            ->where('country', 'UK')
            ->update([
                'jurisdiction' => 'england-and-wales',
                'source' => 'https://www.gov.uk/bank-holidays',
            ]);
    }

    public function down(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->dropIndex('ph_country_jurisdiction_date_index');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['jurisdiction', 'source']);
        });
    }
};
