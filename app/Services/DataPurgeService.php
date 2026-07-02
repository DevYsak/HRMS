<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * DANGER: permanently deletes operational data. Super-Admin only, guarded by
 * type-to-confirm in the UI. Config tables (leave types, shifts, roles, salary
 * structures, performance templates/cycles, companies) are never touched — only
 * transactional records. Every purge runs in a transaction with FK checks off
 * and is written to the log.
 */
class DataPurgeService
{
    /**
     * Bulk-clearable domains → the tables they own (child tables first; FK checks
     * are disabled so order is not strictly required). Missing tables are skipped.
     *
     * @var array<string, array{label:string, tables:array<int,string>}>
     */
    public const DOMAINS = [
        'attendance' => [
            'label' => 'Attendance & breaks',
            'tables' => ['break_logs', 'attendance_regularisations', 'attendance_daily_summaries', 'biometric_logs', 'attendances'],
        ],
        'leave' => [
            'label' => 'Leave requests & balances',
            'tables' => ['leave_balance_adjustments', 'leave_encashments', 'leave_accrual_logs', 'leave_escalations', 'leave_payment_audit_logs', 'leave_requests', 'leave_balances'],
        ],
        'overtime' => [
            'label' => 'Overtime requests',
            'tables' => ['nexflow_ot_sync_logs', 'overtime_records', 'ot_requests'],
        ],
        'payroll' => [
            'label' => 'Payroll & payslips',
            'tables' => ['payslip_items', 'payslips', 'incentives', 'reimbursements', 'salary_revisions', 'payrolls'],
        ],
        'documents' => [
            'label' => 'Documents',
            'tables' => ['document_acknowledgements', 'documents'],
        ],
        'performance' => [
            'label' => 'Performance (reviews, scorecards, KPIs)',
            'tables' => ['performance_review_scores', 'performance_reviews', 'employee_scorecards', 'employee_kpis', 'performance_timelines', 'warning_acknowledgements', 'warning_letters', 'pip_goals', 'pip_records', 'promotion_recommendations', 'review_goals'],
        ],
        'wfh_expenses' => [
            'label' => 'WFH & expense requests',
            'tables' => ['wfh_requests', 'expense_claims'],
        ],
        'notifications' => [
            'label' => 'Notifications & email logs',
            'tables' => ['notifications', 'email_logs'],
        ],
        'audit' => [
            'label' => 'Audit logs',
            'tables' => ['audit_logs'],
        ],
    ];

    /**
     * Row counts per domain (for the dashboard).
     *
     * @return array<string, array{label:string, count:int}>
     */
    public function counts(): array
    {
        $out = [];
        foreach (self::DOMAINS as $key => $domain) {
            $total = 0;
            foreach ($domain['tables'] as $table) {
                if (Schema::hasTable($table)) {
                    $total += DB::table($table)->count();
                }
            }
            $out[$key] = ['label' => $domain['label'], 'count' => $total];
        }

        return $out;
    }

    /**
     * Permanently delete every row in a domain's tables.
     *
     * @throws \InvalidArgumentException
     */
    public function purge(string $domain, User $actor): int
    {
        if (! isset(self::DOMAINS[$domain])) {
            throw new \InvalidArgumentException("Unknown data domain: {$domain}");
        }

        $deleted = 0;
        DB::transaction(function () use ($domain, &$deleted) {
            Schema::disableForeignKeyConstraints();
            foreach (self::DOMAINS[$domain]['tables'] as $table) {
                if (Schema::hasTable($table)) {
                    $deleted += DB::table($table)->delete();
                }
            }
            Schema::enableForeignKeyConstraints();
        });

        Log::warning("[DataPurge] {$actor->email} cleared domain '{$domain}' — {$deleted} rows deleted.");

        return $deleted;
    }

    /**
     * Permanently delete one employee and everything linked to them: every table
     * carrying their employee_id, their notifications, then the employee and the
     * user account. Refuses to delete Super Admins or the actor's own account.
     *
     * @throws \DomainException
     */
    public function deleteEmployee(Employee $employee, User $actor): void
    {
        $user = $employee->user;

        if ($user && $user->id === $actor->id) {
            throw new \DomainException('You cannot delete your own account.');
        }
        if ($user && $user->isSuperAdmin()) {
            throw new \DomainException('Super Admin accounts cannot be deleted here.');
        }

        $employeeId = $employee->id;
        $userId = $employee->user_id;

        DB::transaction(function () use ($employeeId, $userId) {
            Schema::disableForeignKeyConstraints();

            // Grandchild rows keyed by payslip (no employee_id of their own).
            if (Schema::hasTable('payslips') && Schema::hasTable('payslip_items')) {
                $payslipIds = DB::table('payslips')->where('employee_id', $employeeId)->pluck('id');
                if ($payslipIds->isNotEmpty()) {
                    DB::table('payslip_items')->whereIn('payslip_id', $payslipIds)->delete();
                }
            }

            // Every table that references the employee directly.
            foreach ($this->tablesWithEmployeeId() as $table) {
                if ($table === 'employees') {
                    continue;
                }
                DB::table($table)->where('employee_id', $employeeId)->delete();
            }

            if ($userId) {
                DB::table('notifications')
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $userId)
                    ->delete();
            }

            DB::table('employees')->where('id', $employeeId)->delete();
            if ($userId) {
                DB::table('users')->where('id', $userId)->delete();
            }

            Schema::enableForeignKeyConstraints();
        });

        Log::warning("[DataPurge] {$actor->email} permanently deleted employee #{$employeeId} (user #{$userId}).");
    }

    /**
     * Employees that may be bulk-deleted — everyone except Super Admins and the
     * actor's own account. (Employees without a user account are deletable.)
     *
     * @return Collection<int, Employee>
     */
    public function deletableEmployees(User $actor): Collection
    {
        return Employee::with('user')->get()->reject(function (Employee $e) use ($actor) {
            $u = $e->user;

            return $u && ($u->isSuperAdmin() || $u->id === $actor->id);
        })->values();
    }

    /**
     * Permanently delete many employees (cascade each). Protected accounts are
     * skipped, not errored.
     *
     * @param  iterable<int|string>  $employeeIds
     * @return array{deleted:int, skipped:int}
     */
    public function bulkDeleteEmployees(iterable $employeeIds, User $actor): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach ($employeeIds as $id) {
            $employee = Employee::with('user')->find($id);
            if (! $employee) {
                continue;
            }

            try {
                $this->deleteEmployee($employee, $actor);
                $deleted++;
            } catch (\DomainException) {
                $skipped++;
            }
        }

        Log::warning("[DataPurge] {$actor->email} bulk-deleted {$deleted} employee(s), skipped {$skipped}.");

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * All tables that carry an employee_id column.
     *
     * @return array<int, string>
     */
    private function tablesWithEmployeeId(): array
    {
        $tables = [];
        foreach (DB::select('show tables') as $row) {
            $name = array_values((array) $row)[0];
            if (Schema::hasColumn($name, 'employee_id')) {
                $tables[] = $name;
            }
        }

        return $tables;
    }
}
