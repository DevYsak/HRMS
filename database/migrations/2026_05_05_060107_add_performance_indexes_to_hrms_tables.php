<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §6.1 — Indexing Strategy
 * All indexes defined in the spec that were missing from initial migrations.
 * These are critical for query performance on an app with frequent date/status filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── employees ─────────────────────────────────────────────
        Schema::table('employees', function (Blueprint $table) {
            $table->index('status', 'idx_employees_status');
            $table->index('department_id', 'idx_employees_department');
            $table->index('manager_id', 'idx_employees_manager');
            $table->index('shift_id', 'idx_employees_shift');
            $table->index('joining_date', 'idx_employees_joining_date');
            $table->index('probation_end_date', 'idx_employees_probation_end');
        });

        // ── attendances ───────────────────────────────────────────
        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['employee_id', 'date'], 'idx_attendances_employee_date');
            $table->index('date', 'idx_attendances_date');
            $table->index('is_late', 'idx_attendances_late');
            $table->index('excess_break_flag', 'idx_attendances_excess_break');
            $table->index('missing_checkout', 'idx_attendances_missing_checkout');
        });

        // ── break_logs ────────────────────────────────────────────
        Schema::table('break_logs', function (Blueprint $table) {
            $table->index('attendance_id', 'idx_break_logs_attendance');
            $table->index('employee_id', 'idx_break_logs_employee');
        });

        // ── attendance_regularisations ────────────────────────────
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'idx_regularisations_employee_status');
        });

        // ── leave_requests ────────────────────────────────────────
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'idx_leave_requests_employee_status');
            $table->index('start_date', 'idx_leave_requests_start_date');
            $table->index('status', 'idx_leave_requests_status');
        });

        // ── leave_balances ────────────────────────────────────────
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->index(['employee_id', 'leave_type_id', 'year'], 'idx_leave_balances_emp_type_year');
        });

        // ── leave_encashments ─────────────────────────────────────
        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'idx_encashments_employee_status');
        });

        // ── ot_requests ───────────────────────────────────────────
        Schema::table('ot_requests', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'idx_ot_requests_employee_status');
            $table->index('status', 'idx_ot_requests_status');
        });

        // ── overtime_records ──────────────────────────────────────
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->index('employee_id', 'idx_ot_records_employee');
            $table->index('is_paid', 'idx_ot_records_paid');
        });

        // ── payrolls ──────────────────────────────────────────────
        Schema::table('payrolls', function (Blueprint $table) {
            $table->index(['month', 'year', 'cycle'], 'idx_payrolls_month_year_cycle');
            $table->index('status', 'idx_payrolls_status');
        });

        // ── payslips ──────────────────────────────────────────────
        Schema::table('payslips', function (Blueprint $table) {
            $table->index(['employee_id', 'payroll_id'], 'idx_payslips_employee_payroll');
        });

        // ── incentives ────────────────────────────────────────────
        Schema::table('incentives', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'idx_incentives_employee_status');
        });

        // ── reimbursements ────────────────────────────────────────
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'idx_reimbursements_employee_status');
        });

        // ── expense_claims ────────────────────────────────────────
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'idx_expense_claims_employee_status');
        });

        // ── notifications ─────────────────────────────────────────
        // Spec §6.1: idx on (notifiable_id, read_at) for bell-icon badge count
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_id', 'read_at'], 'idx_notifications_notifiable_read');
        });

        // ── performance_reviews ───────────────────────────────────
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'idx_perf_reviews_employee_status');
            $table->index('review_cycle_id', 'idx_perf_reviews_cycle');
        });

        // ── onboarding_tasks ──────────────────────────────────────
        Schema::table('onboarding_tasks', function (Blueprint $table) {
            $table->index(['employee_id', 'is_completed'], 'idx_onboarding_employee_completed');
        });

        // ── documents ────────────────────────────────────────────
        Schema::table('documents', function (Blueprint $table) {
            $table->index('employee_id', 'idx_documents_employee');
            $table->index('expires_at', 'idx_documents_expires');
        });

        // ── audit_logs ────────────────────────────────────────────
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['auditable_type', 'auditable_id'], 'idx_audit_logs_auditable');
            $table->index('user_id', 'idx_audit_logs_user');
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn ($t) => $t->dropIndex(['idx_employees_status', 'idx_employees_department', 'idx_employees_manager', 'idx_employees_shift', 'idx_employees_joining_date', 'idx_employees_probation_end']));
        Schema::table('attendances', fn ($t) => $t->dropIndex(['idx_attendances_employee_date', 'idx_attendances_date', 'idx_attendances_late', 'idx_attendances_excess_break', 'idx_attendances_missing_checkout']));
        Schema::table('break_logs', fn ($t) => $t->dropIndex(['idx_break_logs_attendance', 'idx_break_logs_employee']));
        Schema::table('attendance_regularisations', fn ($t) => $t->dropIndex('idx_regularisations_employee_status'));
        Schema::table('leave_requests', fn ($t) => $t->dropIndex(['idx_leave_requests_employee_status', 'idx_leave_requests_start_date', 'idx_leave_requests_status']));
        Schema::table('leave_balances', fn ($t) => $t->dropIndex('idx_leave_balances_emp_type_year'));
        Schema::table('leave_encashments', fn ($t) => $t->dropIndex('idx_encashments_employee_status'));
        Schema::table('ot_requests', fn ($t) => $t->dropIndex(['idx_ot_requests_employee_status', 'idx_ot_requests_status']));
        Schema::table('overtime_records', fn ($t) => $t->dropIndex(['idx_ot_records_employee', 'idx_ot_records_paid']));
        Schema::table('payrolls', fn ($t) => $t->dropIndex(['idx_payrolls_month_year_cycle', 'idx_payrolls_status']));
        Schema::table('payslips', fn ($t) => $t->dropIndex('idx_payslips_employee_payroll'));
        Schema::table('incentives', fn ($t) => $t->dropIndex('idx_incentives_employee_status'));
        Schema::table('reimbursements', fn ($t) => $t->dropIndex('idx_reimbursements_employee_status'));
        Schema::table('expense_claims', fn ($t) => $t->dropIndex('idx_expense_claims_employee_status'));
        Schema::table('notifications', fn ($t) => $t->dropIndex('idx_notifications_notifiable_read'));
        Schema::table('performance_reviews', fn ($t) => $t->dropIndex(['idx_perf_reviews_employee_status', 'idx_perf_reviews_cycle']));
        Schema::table('onboarding_tasks', fn ($t) => $t->dropIndex('idx_onboarding_employee_completed'));
        Schema::table('documents', fn ($t) => $t->dropIndex(['idx_documents_employee', 'idx_documents_expires']));
        Schema::table('audit_logs', fn ($t) => $t->dropIndex(['idx_audit_logs_auditable', 'idx_audit_logs_user']));
    }
};
