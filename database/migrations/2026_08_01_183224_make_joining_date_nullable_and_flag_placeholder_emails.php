<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real HR records are often incomplete at migration time. Rather than reject
 * those employees, import them and mark what still needs filling in:
 *
 *  - joining_date becomes nullable; a null date means "not supplied yet", and
 *    the features that depend on it (probation, leave accrual, payroll
 *    proration) skip the employee until HR sets it.
 *  - has_placeholder_email marks accounts given a generated address because
 *    the sheet had none, so they can be reported on and never emailed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('joining_date')->nullable()->change();
            $table->boolean('has_placeholder_email')->default(false)->after('employee_code');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('has_placeholder_email');
            // Any null joining dates must be backfilled before rolling back,
            // or MySQL will reject the NOT NULL change.
            $table->date('joining_date')->nullable(false)->change();
        });
    }
};
