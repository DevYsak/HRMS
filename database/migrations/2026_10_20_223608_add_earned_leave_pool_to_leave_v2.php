<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave V2 — one shared Earned Leave balance.
 *
 * Additive only. A policy names the leave type that holds the Earned Leave
 * balance; Casual, Sick, Privilege… are reasons that draw on it and hold no
 * balance of their own. A request keeps its reason in leave_type_id and the
 * balance it charges in balance_leave_type_id. Existing rows keep NULL, and
 * NULL means "charged to leave_type_id" — exactly how they were charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_policies', function (Blueprint $table) {
            $table->foreignId('earned_leave_type_id')->nullable()->after('annual_paid_leave_cap')
                ->constrained('leave_types')->nullOnDelete();
        });

        Schema::table('leave_types', function (Blueprint $table) {
            // Special leave normally keeps its own entitlement; HR may make a
            // special type draw on Earned Leave instead.
            $table->boolean('consumes_earned_leave')->default(false)->after('counts_toward_annual_cap');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('main_category', 20)->nullable()->after('leave_type_id')->index();
            $table->foreignId('balance_leave_type_id')->nullable()->after('main_category')
                ->constrained('leave_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('balance_leave_type_id');
            $table->dropIndex(['main_category']);
            $table->dropColumn('main_category');
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('consumes_earned_leave');
        });

        Schema::table('leave_policies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('earned_leave_type_id');
        });
    }
};
