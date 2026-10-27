<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When automatic monthly Earned Leave accrual takes over for an employee who
 * already holds an Earned Leave balance (a migrated opening or an up-front
 * grant). From the 1st of that month the accrual engine credits each
 * completed month; the balance held before stays exactly as it is.
 *
 * One row per employee: employees are moved across on dates HR decides, and
 * the date outlives any one leave year. A new joiner needs no row — their
 * accrual starts from their own eligibility. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_accrual_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            // Always the 1st of a month: accrual is month by month.
            $table->date('starts_on');
            $table->text('reason')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_accrual_transitions');
    }
};
