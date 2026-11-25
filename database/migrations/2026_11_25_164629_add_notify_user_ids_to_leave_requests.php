<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notify Others (25 Nov 2026): the people the employee chose to tell about
 * a leave application. They get the application and every later decision
 * on it, besides the manager, approvers and HR who are always told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->json('notify_user_ids')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('notify_user_ids');
        });
    }
};
