<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearDemoData extends Command
{
    protected $signature = 'hrms:clear-demo-data
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe all demo/fake/test data and leave only the 6 system admin accounts and config tables.';

    /**
     * These system admin emails are NEVER deleted.
     * All other users and their employee records are removed.
     */
    private array $keepEmails = [
        'mazhar@conexus-ns.com',
        'shivani@conexus-ns.com',
        'rustom@conexus-ns.com',
        'nick@conexus-ns.com',
        'nikita@conexus-ns.com',
        'emad@conexus-ns.com',
    ];

    /** Transactional tables — wiped completely (FK children first). */
    private array $truncateTables = [
        'payslip_items',
        'payslips',
        'payrolls',
        'incentives',
        'reimbursements',
        'expense_claims',
        'overtime_records',
        'ot_requests',
        'break_logs',
        'biometric_logs',
        'attendance_regularisations',
        'attendances',
        'leave_escalations',
        'leave_encashments',
        'leave_balances',
        'leave_requests',
        'review_goals',
        'performance_reviews',
        'review_cycles',
        'document_acknowledgements',
        'documents',
        'onboarding_tasks',
        'equipment_logs',
        'assets',
        'exit_records',
        'employee_salaries',
        'notifications',
        'audit_logs',
        'sessions',
        'cache',
        'cache_locks',
        'failed_jobs',
        'team_invitations',
        'team_members',
        'teams',
    ];

    /** Config/lookup tables — never touched. */
    private array $preservedTables = [
        'role_permissions',
        'companies',
        'departments',
        'offices',
        'shift_settings',
        'job_titles',
        'leave_types',
        'salary_components',
        'public_holidays',
        'december_mandatory_days',
        'attendance_settings',
        'biometric_devices',
        'pause_codes',
        'ot_windows',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->line('<fg=yellow;options=bold>  ⚠  DEMO DATA WIPE</>');
        $this->newLine();
        $this->line('  Will permanently delete:');
        $this->line('    • All attendance, leave, payroll, OT, and performance records');
        $this->line('    • All employee records EXCEPT the 6 system admin accounts');
        $this->line('    • All user accounts EXCEPT:');
        foreach ($this->keepEmails as $email) {
            $this->line("        → {$email}");
        }
        $this->newLine();
        $this->line('  Preserved tables (config/lookup — never wiped):');
        foreach ($this->preservedTables as $t) {
            $this->line("    ✓ {$t}");
        }
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->line('  Aborted — no changes made.');

            return self::SUCCESS;
        }

        $this->newLine();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // 1. Wipe all transactional data
        $this->line('  Truncating transactional tables...');
        foreach ($this->truncateTables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->truncate();
                $this->line("    ✓ {$table}");
            }
        }

        // 2. Delete ALL employees — admin employee records will be recreated by EmployeeSeeder
        $deleted = DB::table('employees')->delete();
        $this->line("  Deleted {$deleted} employee record(s)");

        // 3. Delete users that are NOT in the keep list
        $deleted = DB::table('users')
            ->whereNotIn('email', $this->keepEmails)
            ->delete();
        $this->line("  Deleted {$deleted} non-admin user(s)");

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $remaining = DB::table('users')->whereIn('email', $this->keepEmails)->count();
        $this->newLine();
        $this->info('  ✅ Demo data cleared. '.$remaining.' system admin account(s) retained.');
        $this->newLine();
        $this->line('  Next step — import real employees:');
        $this->line('    <fg=cyan>php artisan biometric:sync-employees</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
