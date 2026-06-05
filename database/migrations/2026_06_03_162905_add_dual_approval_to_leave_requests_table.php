<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Second-stage HR approval fields (manager approves first → pending_hr → HR signs off)
            $table->foreignId('hr_reviewer_id')->nullable()->after('reviewer_comment')
                ->constrained('users')->nullOnDelete();
            $table->string('hr_reviewer_comment')->nullable()->after('hr_reviewer_id');
            $table->timestamp('hr_reviewed_at')->nullable()->after('hr_reviewer_comment');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hr_reviewer_id');
            $table->dropColumn(['hr_reviewer_comment', 'hr_reviewed_at']);
        });
    }
};
