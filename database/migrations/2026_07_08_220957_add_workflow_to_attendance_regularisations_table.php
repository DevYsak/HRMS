<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-stage approval workflow for regularisations:
     * manager_review → hr_review → admin_approval → approved/rejected.
     * approval_trail keeps the full audit history of every stage action;
     * attachment_path stores the employee's optional supporting document.
     */
    public function up(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->string('stage', 20)->default('manager_review')->after('status');
            $table->json('approval_trail')->nullable()->after('reviewer_comment');
            $table->string('attachment_path')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->dropColumn(['stage', 'approval_trail', 'attachment_path']);
        });
    }
};
