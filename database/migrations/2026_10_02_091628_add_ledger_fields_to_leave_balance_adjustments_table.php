<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An HR adjustment says which year it belongs to, what kind of movement it
 * is, when it takes effect and — for an add-on — when it expires. Without a
 * year, an adjustment could only ever be matched to a balance by guessing
 * from its timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balance_adjustments', function (Blueprint $table) {
            $table->foreignId('leave_year_id')->nullable()->after('leave_type_id')->constrained('leave_years')->nullOnDelete();
            // adjustment | add_on | correction
            $table->string('category', 30)->nullable()->after('source');
            // manual_adjustment | special_leave | management_grant | comp_off_credit | ...
            $table->string('add_on_type', 40)->nullable()->after('category');
            $table->date('effective_date')->nullable()->after('add_on_type');
            $table->date('expires_on')->nullable()->after('effective_date');
            $table->text('internal_note')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balance_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_year_id');
            $table->dropColumn(['category', 'add_on_type', 'effective_date', 'expires_on', 'internal_note']);
        });
    }
};
