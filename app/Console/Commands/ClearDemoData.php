<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearDemoData extends Command
{
    protected $signature = 'app:clear-demo-data
                            {--keep-admins : Keep users with super_admin role instead of deleting them}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Remove all demo/test data while preserving roles, system config, and optionally super admin accounts.';

    /**
     * Truncated in order: children before parents so FK constraints are satisfied.
     */
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

    private array $keptTables = [
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
    ];

    public function handle(): int
    {
        $keepAdmins = $this->option('keep-admins');

        $this->newLine();
        $this->line('<fg=yellow;options=bold>  ⚠  DEMO DATA WIPE</>');
        $this->newLine();
        $this->line('  This will permanently delete:');
        $this->line('    • All employees and their user accounts'.($keepAdmins ? ' <fg=green>(super_admin users kept)</>' : ''));
        $this->line('    • All attendance, leave, payroll, OT, and performance records');
        $this->line('    • All documents, assets, expenses, and notifications');
        $this->newLine();
        $this->line('  The following will be <fg=green>preserved</>:');
        foreach ($this->keptTables as $table) {
            $this->line("    ✓ {$table}");
        }
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Are you absolutely sure you want to proceed?', false)) {
            $this->line('  Aborted. No changes made.');

            return self::SUCCESS;
        }

        $this->newLine();

        $this->line('  Disabling foreign key checks...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $this->line('  Truncating transactional tables...');
        foreach ($this->truncateTables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->truncate();
                $this->line("    ✓ {$table}");
            }
        }

        $this->line('  Deleting employees...');
        $deleted = DB::table('employees')->delete();
        $this->line("    ✓ {$deleted} employee record(s) deleted");

        $this->line('  Deleting users...');
        $query = DB::table('users');
        if ($keepAdmins) {
            $query->where('role', '!=', 'super_admin');
        }
        $deleted = $query->delete();
        $this->line("    ✓ {$deleted} user(s) deleted");

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine();
        $this->info('  ✅ Demo data cleared successfully.');

        if ($keepAdmins) {
            $remaining = DB::table('users')->where('role', 'super_admin')->count();
            $this->line("  {$remaining} super_admin user(s) retained.");
        } else {
            $this->newLine();
            $this->line('  All users deleted. Create a fresh admin account:');
            $this->line('  <fg=cyan>  php artisan tinker</>');
            $this->line('  <fg=cyan>  App\Models\User::create([\'name\'=>\'Admin\',\'email\'=>\'you@email.com\',\'password\'=>bcrypt(\'yourpassword\'),\'role\'=>\'super_admin\'])</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
