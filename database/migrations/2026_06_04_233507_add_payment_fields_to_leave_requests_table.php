<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->enum('half_day_period', ['first_half', 'second_half'])->nullable()->after('is_half_day');
            $table->enum('requested_leave_status', ['paid', 'unpaid'])->nullable()->after('half_day_period');
            $table->enum('approved_leave_status', ['paid', 'unpaid'])->nullable()->after('requested_leave_status');
            $table->foreignId('payment_status_changed_by')->nullable()->after('approved_leave_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('payment_status_changed_at')->nullable()->after('payment_status_changed_by');
            $table->text('payment_status_change_reason')->nullable()->after('payment_status_changed_at');
            $table->text('hr_remark')->nullable()->after('payment_status_change_reason');
            $table->string('attachment_path')->nullable()->after('hr_remark');
        });

        // Expand status enum to include pending_hr and withdrawn
        DB::statement("
            ALTER TABLE `leave_requests`
            MODIFY `status` ENUM('pending','pending_hr','approved','rejected','cancelled','withdrawn')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE `leave_requests`
            MODIFY `status` ENUM('pending','approved','rejected','cancelled')
            NOT NULL DEFAULT 'pending'
        ");

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_status_changed_by');
            $table->dropColumn([
                'half_day_period', 'requested_leave_status', 'approved_leave_status',
                'payment_status_changed_at', 'payment_status_change_reason',
                'hr_remark', 'attachment_path',
            ]);
        });
    }
};
