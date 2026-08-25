<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * BiometricEmployeeMasterSeeder
 *
 * Single source of truth for real Conexus employees.
 * Each record maps directly to the biometric device enrolment.
 *
 * RULES:
 *   - employee_code is the ONLY key used for attendance mapping.
 *   - Attendance is NEVER matched by name or email.
 *   - Add new employees here; run `php artisan db:seed --class=BiometricEmployeeMasterSeeder`
 *     or `php artisan biometric:sync-employees` to apply changes without wiping other data.
 *
 * SHIFT CODES:
 *   'IT Shift'      → 10:30–19:30 IST  (Dev, Ops, Marketing, HR, Finance, Admin)
 *   'UK Sales Shift'→ 13:00–22:00 IST  (UK Sales, BD)
 */
class BiometricEmployeeMasterSeeder extends Seeder
{
    /**
     * Biometric employee master data.
     *
     * Keys:
     *   employee_code  int     — biometric device enrolment number (attendance key)
     *   name           string  — full name
     *   email          string  — work email (used to create login account)
     *   shift          string  — 'IT Shift' | 'UK Sales Shift'
     *   dept           string  — department code (ADMIN|HR|PRD|SALES|OPS|MKT|FIN)
     *   joining_date   string  — YYYY-MM-DD  (optional, defaults to today)
     *   role           string  — UserRole value (optional, defaults to 'employee').
     *                            Declared here so re-running the seeder never
     *                            silently demotes an account back to 'employee'.
     *
     * To add more employees: append rows to this array.
     *
     * @var list<array{employee_code:int,name:string,email:string,shift:string,dept:string,joining_date?:string,role?:string}>
     */
    private array $employees = [
        [
            'employee_code' => 17,
            'name' => 'Yogesh Sakpal',
            'email' => 'yogesh.sakpal@conexus-ns.com',
            'shift' => 'IT Shift',
            'dept' => 'PRD',
            'joining_date' => '2022-04-01',
            'role' => 'super_admin',
        ],
        // ─────────────────────────────────────────────────────
        // ADD MORE EMPLOYEES BELOW — copy the block above.
        // employee_code MUST match the biometric device enrolment number.
        // ─────────────────────────────────────────────────────
    ];

    public function run(): void
    {
        $shifts = ShiftSetting::all()->keyBy('name');
        $depts = Department::all()->keyBy('code');

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->employees as $data) {
            $shift = $shifts->get($data['shift']);
            $dept = $depts->get($data['dept'] ?? '');

            if (! $shift) {
                $this->command->warn("  Shift '{$data['shift']}' not found — skipping {$data['name']}");
                $skipped++;

                continue;
            }

            // 1. Upsert User account (login credentials)
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),  // force-reset on first login recommended
                    'role' => $data['role'] ?? 'employee',
                ]
            );

            // 2. Upsert Employee record — employee_code is the canonical biometric key
            $employeeCode = (int) $data['employee_code'];
            $joiningDate = $data['joining_date'] ?? now()->toDateString();

            $existing = Employee::where('employee_code', $employeeCode)->first()
                ?? Employee::where('user_id', $user->id)->first();

            if ($existing) {
                $existing->update([
                    'user_id' => $user->id,
                    'employee_code' => $employeeCode,
                    'department_id' => $dept?->id,
                    'shift_id' => $shift->id,
                    'status' => 'active',
                ]);
                $updated++;
            } else {
                Employee::create([
                    'user_id' => $user->id,
                    'employee_id' => 'EMP-'.str_pad((string) $employeeCode, 4, '0', STR_PAD_LEFT),
                    'employee_code' => $employeeCode,
                    'department_id' => $dept?->id,
                    'shift_id' => $shift->id,
                    'joining_date' => $joiningDate,
                    'status' => 'active',
                    'salary_cycle' => 'A',
                    'sync_status' => 'pending',
                ]);
                $created++;
            }
        }

        $this->command->info(
            "  BiometricEmployeeMasterSeeder: {$created} created, {$updated} updated, {$skipped} skipped."
        );
    }
}
