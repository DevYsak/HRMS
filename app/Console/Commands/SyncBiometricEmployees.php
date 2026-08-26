<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\PasswordService;
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

    /**
     * The roster to sync.
     *
     * Defaults to the master list above, but `biometric.employee_master` wins
     * when set — so a deployment can supply the roster through config instead
     * of editing this file, and tests can drive the command without touching
     * the real list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function roster(): array
    {
        return config('biometric.employee_master') ?: $this->masterData;
    }

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

        foreach ($this->roster() as $data) {
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
                // 1. Upsert User login account.
                //
                // Password and role are create-only. They used to sit in the
                // update half of updateOrCreate, so every run rewrote both:
                // an employee's chosen password was replaced with the literal
                // string "password", and anyone promoted to manager, HR or
                // director was silently demoted back to employee. A sync is
                // an employee-directory feed; it has no business holding an
                // opinion about credentials or privileges.
                // Identity is resolved by employee_code first — the key this
                // command documents as its anchor. Matching on email alone
                // meant a corrected address in the master produced a second
                // user and orphaned the first, which kept its login and role.
                $existingEmployee = Employee::where('employee_code', $code)->first();
                $user = $existingEmployee?->user ?? User::where('email', $data['email'])->first();

                if ($user) {
                    // Only what this command owns.
                    if ($user->email !== $data['email']) {
                        $this->line("  <fg=yellow>EMAIL</>   code={$code}  {$user->email} → {$data['email']}");
                    }

                    $user->update(['name' => $data['name'], 'email' => $data['email']]);
                } else {
                    $user = User::create([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        // Nobody has been told this password — it exists only so
                        // the account is never left with a guessable one. The
                        // employee reaches their account through the
                        // forgot-password flow.
                        'password' => Hash::make(app(PasswordService::class)->generate()),
                        'role' => 'employee',
                    ]);
                }

                // 2. Upsert Employee record — employee_code is the anchor
                $existing = $existingEmployee ?? Employee::where('user_id', $user->id)->first();

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
