<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveDemoEmployees extends Command
{
    protected $signature = 'app:remove-demo-employees
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete demo employees (email @example.com/.net/.org) and all their related data, leaving real employees untouched.';

    public function handle(): int
    {
        $demoUserIds = DB::table('users')
            ->where(function ($q) {
                $q->where('email', 'like', '%@example.com')
                    ->orWhere('email', 'like', '%@example.net')
                    ->orWhere('email', 'like', '%@example.org')
                    ->orWhere('email', 'like', '%@example.edu');
            })
            ->pluck('id');

        $demoEmployeeIds = DB::table('employees')
            ->whereIn('user_id', $demoUserIds)
            ->pluck('id');

        $this->newLine();
        $this->line('<fg=yellow;options=bold>  ⚠  DEMO EMPLOYEE REMOVAL</>');
        $this->newLine();
        $this->line("  Demo users found    : <fg=cyan>{$demoUserIds->count()}</>");
        $this->line("  Demo employees found: <fg=cyan>{$demoEmployeeIds->count()}</>");
        $this->newLine();
        $this->line('  All attendance, leave, payroll, and related records for these employees will also be removed.');
        $this->line('  Real company employees and admin accounts will NOT be touched.');
        $this->newLine();

        if ($demoUserIds->isEmpty()) {
            $this->info('  No demo users found. Nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->line('  Aborted. No changes made.');

            return self::SUCCESS;
        }

        $this->newLine();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $leaveRequestIds = DB::table('leave_requests')->whereIn('employee_id', $demoEmployeeIds)->pluck('id');
        $payslipIds = DB::table('payslips')->whereIn('employee_id', $demoEmployeeIds)->pluck('id');
        $reviewIds = DB::table('performance_reviews')->whereIn('employee_id', $demoEmployeeIds)->pluck('id');

        $deletes = [
            'payslip_items' => fn () => DB::table('payslip_items')->whereIn('payslip_id', $payslipIds)->delete(),
            'payslips' => fn () => DB::table('payslips')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'incentives' => fn () => DB::table('incentives')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'reimbursements' => fn () => DB::table('reimbursements')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'expense_claims' => fn () => DB::table('expense_claims')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'overtime_records' => fn () => DB::table('overtime_records')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'ot_requests' => fn () => DB::table('ot_requests')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'break_logs' => fn () => DB::table('break_logs')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'biometric_logs' => fn () => DB::table('biometric_logs')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'attendance_regularisations' => fn () => DB::table('attendance_regularisations')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'attendances' => fn () => DB::table('attendances')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'leave_escalations' => fn () => DB::table('leave_escalations')->whereIn('leave_request_id', $leaveRequestIds)->delete(),
            'leave_encashments' => fn () => DB::table('leave_encashments')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'leave_balances' => fn () => DB::table('leave_balances')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'leave_requests' => fn () => DB::table('leave_requests')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'review_goals' => fn () => DB::table('review_goals')->whereIn('performance_review_id', $reviewIds)->delete(),
            'performance_reviews' => fn () => DB::table('performance_reviews')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'document_acknowledgements' => fn () => DB::table('document_acknowledgements')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'documents' => fn () => DB::table('documents')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'onboarding_tasks' => fn () => DB::table('onboarding_tasks')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'assets' => fn () => DB::table('assets')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'exit_records' => fn () => DB::table('exit_records')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'employee_salaries' => fn () => DB::table('employee_salaries')->whereIn('employee_id', $demoEmployeeIds)->delete(),
            'notifications' => fn () => DB::table('notifications')->whereIn('notifiable_id', $demoUserIds)->where('notifiable_type', 'App\\Models\\User')->delete(),
            'audit_logs' => fn () => DB::table('audit_logs')->whereIn('user_id', $demoUserIds)->delete(),
            'sessions' => fn () => DB::table('sessions')->whereIn('user_id', $demoUserIds)->delete(),
        ];

        foreach ($deletes as $table => $deleter) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $count = $deleter();
                if ($count > 0) {
                    $this->line("    ✓ {$table}: {$count} row(s) removed");
                }
            }
        }

        $empDeleted = DB::table('employees')->whereIn('id', $demoEmployeeIds)->delete();
        $this->line("    ✓ employees: {$empDeleted} removed");

        $userDeleted = DB::table('users')->whereIn('id', $demoUserIds)->delete();
        $this->line("    ✓ users: {$userDeleted} removed");

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine();
        $this->info('  ✅ Demo employees removed successfully.');
        $this->newLine();

        return self::SUCCESS;
    }
}
