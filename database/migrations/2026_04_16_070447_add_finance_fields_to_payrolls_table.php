<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            // Breakdown columns
            $table->decimal('ot_amount', 12, 2)->default(0)->after('total_payout');
            $table->decimal('incentives', 12, 2)->default(0)->after('ot_amount');
            $table->decimal('reimbursements', 12, 2)->default(0)->after('incentives');
            $table->decimal('deductions', 12, 2)->default(0)->after('reimbursements');

            // Finance approval workflow
            // status enum extension: draft → pending_finance → approved → processed
            $table->foreignId('finance_approved_by')->nullable()->constrained('users')->nullOnDelete()->after('processed_by');
            $table->timestamp('finance_approved_at')->nullable()->after('finance_approved_by');
            $table->text('finance_note')->nullable()->after('finance_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropForeign(['finance_approved_by']);
            $table->dropColumn([
                'ot_amount', 'incentives', 'reimbursements', 'deductions',
                'finance_approved_by', 'finance_approved_at', 'finance_note',
            ]);
        });
    }
};
