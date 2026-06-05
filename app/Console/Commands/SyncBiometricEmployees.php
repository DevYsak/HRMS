<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * biometric:sync-employees
 *
 * Imports / updates employees from the biometric employee master.
 * Uses employee_code as the sole matching key — NEVER name or email.
 *
 * Safe to run repeatedly:
 *   - If employee_code already exists  → updates name, email, shift, dept.
 *   - If employee_code is new          → creates User + Employee records.
 *   - Attendance records are NEVER touched by this command.
 *
 * Usage:
 *   php artisan biometric:sync-employees
 *   php artisan biometric:sync-employees --dry-run
 */
class SyncBiometricEmployees extends Command
{
    protected $signature = 'biometric:sync-employees
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Upsert employees from the biometric master using employee_code as the key.';

    /**
     * ─────────────────────────────────────────────────────────────────────
     * BIOMETRIC EMPLOYEE MASTER
     *
     * This is the single source of truth for all real employees.
     * Each record MUST have an employee_code matching the biometric device.
     *
     * Fields:
     *   employee_code  int     — biometric device user number (attendance matching key)
     *   name           string  — full name
     *   email          string  — work email
     *   shift          string  — 'IT Shift' or 'UK Sales Shift'
     *   dept           string  — department code
     *   joining_date   string  — YYYY-MM-DD (optional)
     * ─────────────────────────────────────────────────────────────────────
     */
    private array $masterData = [
        [
            'employee_code' => 17,
            'name' => 'Yogesh Sakpal',
            'email' => 'yogesh.sakpal@conexus-ns.com',
            'shift' => 'IT Shift',
            'dept' => 'PRD',
            'joining_date' => '2022-04-01',
        ],
        // ── Add more employees here ──────────────────────────────────────
        // Copy the block above. employee_code MUST match the device enrolment.
        // ────────────────────────────────────────────────────────────────
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('  DRY RUN — no database changes will be made.');
            $this->newLine();
        }

        $shifts = ShiftSetting::all()->keyBy('name');
        $depts = Department::all()->keyBy('code');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($this->masterData as $data) {
            $code = (int) $data['employee_code'];

            // Validate shift exists
            $shift = $shifts->get($data['shift']);

            if (! $shift) {
                $errors[] = "  employee_code={$code} ({$data['name']}): shift '{$data['shift']}' not found.";
                $skipped++;

                continue;
            }

            $dept = $depts->get($data['dept'] ?? '');
            $joining = $data['joining_date'] ?? now()->toDateString();

            if ($isDryRun) {
                $existing = Employee::where('employee_code', $code)->exists();
                $action = $existing ? 'UPDATE' : 'CREATE';
                $this->line("  [{$action}] code={$code}  {$data['name']}  <{$data['email']}>  shift={$data['shift']}");

                continue;
            }

            try {
                // 1. Upsert User login account
                $user = User::updateOrCreate(
                    ['email' => $data['email']],
                    [
                        'name' => $data['name'],
                        'password' => Hash::make('password'),
                        'role' => 'employee',
                    ]
                );

                // 2. Upsert Employee record — employee_code is the anchor
                $existing = Employee::where('employee_code', $code)->first()
                    ?? Employee::where('user_id', $user->id)->first();

                if ($existing) {
                    $existing->update([
                        'user_id' => $user->id,
                        'employee_code' => $code,
                        'department_id' => $dept?->id,
                        'shift_id' => $shift->id,
                        'status' => 'active',
                    ]);
                    $updated++;
                    $this->line("  <fg=cyan>UPDATED</> code={$code}  {$data['name']}");
                } else {
                    Employee::create([
                        'user_id' => $user->id,
                        'employee_id' => 'EMP-'.str_pad((string) $code, 4, '0', STR_PAD_LEFT),
                        'employee_code' => $code,
                        'department_id' => $dept?->id,
                        'shift_id' => $shift->id,
                        'joining_date' => $joining,
                        'status' => 'active',
                        'salary_cycle' => 'A',
                        'sync_status' => 'pending',
                    ]);
                    $created++;
                    $this->line("  <fg=green>CREATED</> code={$code}  {$data['name']}");
                }
            } catch (\Throwable $e) {
                $errors[] = "  employee_code={$code} ({$data['name']}): {$e->getMessage()}";
                $skipped++;
            }
        }

        $this->newLine();

        if (! $isDryRun) {
            $this->info("  Done — {$created} created, {$updated} updated, {$skipped} skipped.");
        }

        if ($errors) {
            $this->newLine();
            $this->error('  Errors:');
            foreach ($errors as $err) {
                $this->line($err);
            }
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
