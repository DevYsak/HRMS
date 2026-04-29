<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX 2 — Spec §3.3 Leave Management
 * Adds is_half_day boolean so employees can request a half-day of leave.
 * When true, the leave deducts 0.5 days instead of 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('is_half_day')->default(false)->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('is_half_day');
        });
    }
};
