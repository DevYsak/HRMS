<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_encashments', function (Blueprint $table) {
            // Expand status to include pending_finance between manager-approved and finance-approved
            $table->enum('status', ['pending', 'pending_finance', 'approved', 'rejected', 'processed'])
                ->default('pending')
                ->change();

            // Finance second-stage approval columns
            $table->foreignId('finance_reviewer_id')->nullable()->constrained('users')->nullOnDelete()->after('reviewed_at');
            $table->text('finance_reviewer_comment')->nullable()->after('finance_reviewer_id');
            $table->timestamp('finance_reviewed_at')->nullable()->after('finance_reviewer_comment');
        });
    }

    public function down(): void
    {
        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->dropForeign(['finance_reviewer_id']);
            $table->dropColumn(['finance_reviewer_id', 'finance_reviewer_comment', 'finance_reviewed_at']);
            $table->enum('status', ['pending', 'approved', 'rejected', 'processed'])->default('pending')->change();
        });
    }
};
