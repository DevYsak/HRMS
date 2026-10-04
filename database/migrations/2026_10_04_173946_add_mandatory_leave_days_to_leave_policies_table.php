<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many company-shutdown (Mandatory December Leave) days a policy carries.
 *
 * MDL is not a balance: the days themselves are dated rows in
 * december_mandatory_days. This states how many of them the policy
 * expects (Conexus: six), so a December with the wrong number of
 * configured dates can be flagged instead of silently accepted.
 *
 * Additive only — null on every existing policy, which means "no MDL".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_policies', function (Blueprint $table) {
            $table->unsignedTinyInteger('mandatory_leave_days')->nullable()->after('irregular_accrual_rate');
        });
    }

    public function down(): void
    {
        Schema::table('leave_policies', function (Blueprint $table) {
            $table->dropColumn('mandatory_leave_days');
        });
    }
};
