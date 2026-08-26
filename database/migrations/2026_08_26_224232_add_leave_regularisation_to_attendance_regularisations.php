<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave regularisation, carried by the request table that already exists.
 *
 * A missed punch and an unrecorded absence are the same shape of problem — a
 * past day recorded wrongly, corrected by someone with authority to say so —
 * and they already have a working manager → HR → admin chain with its
 * approvers, notifications, observer and audit trail. A second pipeline would
 * duplicate every one of those and then drift from it.
 *
 * So the request gains a category. Existing rows default to 'attendance' and
 * behave exactly as before; a leave regularisation carries the extra fields
 * below and takes a different branch at final approval only.
 *
 * leave_balance_adjustments gains a source, so a day deducted by a
 * regularisation is distinguishable from an HR correction in payroll and
 * reporting rather than looking like a manual debit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            // attendance | leave. Defaulted so every existing row keeps its
            // current meaning without a backfill.
            $table->string('category', 20)->default('attendance')->after('regularisation_type');

            $table->foreignId('leave_type_id')->nullable()->after('category')
                ->constrained()->nullOnDelete();

            // work_date remains the single day an attendance correction targets.
            // A leave regularisation can span a range, so it carries its own.
            $table->date('from_date')->nullable()->after('leave_type_id');
            $table->date('to_date')->nullable()->after('from_date');
            $table->decimal('duration', 5, 2)->nullable()->after('to_date');

            $table->text('remarks')->nullable()->after('reason');

            // Both sides of the balance change, captured at approval. A final
            // figure alone cannot answer "what did this request do".
            $table->decimal('previous_balance', 8, 2)->nullable()->after('applied_via');
            $table->decimal('new_balance', 8, 2)->nullable()->after('previous_balance');

            // The attendance status the day held before approval, so the
            // correction can be described — and reversed by hand if it ever
            // has to be.
            $table->string('previous_attendance_status', 32)->nullable()->after('new_balance');

            $table->foreignId('cancelled_by')->nullable()->after('previous_attendance_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');

            $table->index(['category', 'status'], 'ar_category_status_index');
        });

        // Cancellation is a real outcome of this workflow and had nowhere to be
        // recorded. Additive: no existing row changes.
        DB::statement("ALTER TABLE attendance_regularisations MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending'");

        // A day covered by approved leave is not an absence, and calling it one
        // is what the whole feature exists to correct.
        DB::statement("ALTER TABLE attendances MODIFY status ENUM('on_time','late','half_day','absent','remote','holiday_worked','leave') NOT NULL DEFAULT 'on_time'");

        Schema::table('leave_balance_adjustments', function (Blueprint $table) {
            // manual | regularisation | carry_forward. Nullable rather than
            // defaulted: an existing row genuinely has no recorded source, and
            // claiming otherwise would be a guess written into the audit trail.
            $table->string('source', 32)->nullable()->after('action');
            $table->unsignedBigInteger('source_id')->nullable()->after('source');

            $table->index(['source', 'source_id'], 'lba_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balance_adjustments', function (Blueprint $table) {
            $table->dropIndex('lba_source_index');
            $table->dropColumn(['source', 'source_id']);
        });

        DB::statement("ALTER TABLE attendances MODIFY status ENUM('on_time','late','half_day','absent','remote','holiday_worked') NOT NULL DEFAULT 'on_time'");
        DB::statement("ALTER TABLE attendance_regularisations MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");

        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->dropIndex('ar_category_status_index');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('leave_type_id');
            $table->dropColumn([
                'category', 'from_date', 'to_date', 'duration', 'remarks',
                'previous_balance', 'new_balance', 'previous_attendance_status', 'cancelled_at',
            ]);
        });
    }
};
