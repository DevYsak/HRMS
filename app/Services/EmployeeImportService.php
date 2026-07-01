<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Mail\WelcomeEmployeeMail;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeImportLog;
use App\Models\EmploymentType;
use App\Models\JobTitle;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Parses and imports bulk employee spreadsheets.
 *
 * Deliberately free of any Excel-library dependency: it operates on plain
 * associative arrays (keyed by the slugged column headings), so the whole
 * validate → preview → import pipeline is unit-testable. SpreadsheetService is
 * the only piece that touches the spreadsheet library (openspout).
 */
class EmployeeImportService
{
    /** Canonical column keys (== slugged spreadsheet headings). */
    public const COLUMNS = [
        'employee_id', 'first_name', 'middle_name', 'last_name', 'email', 'gender',
        'date_of_birth', 'phone', 'emergency_contact', 'address', 'department',
        'designation', 'role', 'reporting_manager', 'joining_date', 'employment_type',
        'shift', 'status', 'biometric_pin', 'ctc', 'pf_number', 'esi_number', 'pan',
        'aadhaar', 'bank_name', 'account_number', 'ifsc', 'password',
    ];

    /** Human-readable headings for the downloadable template (slug == COLUMNS). */
    public function templateHeadings(): array
    {
        return [
            'Employee ID', 'First Name', 'Middle Name', 'Last Name', 'Email', 'Gender',
            'Date Of Birth', 'Phone', 'Emergency Contact', 'Address', 'Department',
            'Designation', 'Role', 'Reporting Manager', 'Joining Date', 'Employment Type',
            'Shift', 'Status', 'Biometric PIN', 'CTC', 'PF Number', 'ESI Number', 'PAN',
            'Aadhaar', 'Bank Name', 'Account Number', 'IFSC', 'Password',
        ];
    }

    /** A single illustrative sample row for the template. */
    public function sampleRow(): array
    {
        return [
            'EMP1001', 'Asha', '', 'Verma', 'asha.verma@example.com', 'female',
            '1994-05-12', '9876543210', '9876500000', '12 MG Road, Pune', 'Engineering',
            'Software Engineer', 'employee', 'manager@example.com', '2026-07-01', 'Full Time',
            'IT Shift', 'active', '101', '600000', 'MH/BAN/12345/123', '31000000000000000',
            'ABCDE1234F', '123456789012', 'HDFC Bank', '50100000000000', 'HDFC0001234', '',
        ];
    }

    /**
     * Validate + resolve raw rows into a preview.
     *
     * @param  array<int, array<string, mixed>>  $rows  keyed by slugged heading
     * @return array{rows: array<int, array{line:int, data:array, status:string, errors:array<int,string>}>, summary: array<string,int>}
     */
    public function parse(array $rows): array
    {
        $departments = $this->lookup(Department::all());
        $jobTitles = $this->lookup(JobTitle::all());
        $employmentTypes = $this->lookup(EmploymentType::all());
        $shifts = $this->lookup(ShiftSetting::all());
        $managers = User::query()->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [Str::lower((string) $email) => $id]);
        $existingEmails = User::query()->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [Str::lower((string) $email) => $id]);
        $existingEmployeeIds = Employee::query()->pluck('employee_id')->filter()->map(fn ($v) => (string) $v)->all();

        $seen = [];
        $seenEmpIds = [];
        $out = [];
        $summary = ['total' => 0, 'new' => 0, 'update' => 0, 'error' => 0];

        foreach ($rows as $i => $raw) {
            $r = $this->normalize($raw);
            if ($this->isBlank($r)) {
                continue;
            }

            $summary['total']++;
            $line = $i + 2; // +1 for zero-index, +1 for the heading row
            $errors = [];

            $email = Str::lower((string) $r['email']);
            $name = trim(implode(' ', array_filter([$r['first_name'], $r['middle_name'], $r['last_name']])));

            if ($name === '') {
                $errors[] = 'First name is required.';
            }
            if ($email === '') {
                $errors[] = 'Email is required.';
            } elseif (! Validator::make(['e' => $email], ['e' => 'email'])->passes()) {
                $errors[] = 'Email is not a valid address.';
            } elseif (isset($seen[$email])) {
                $errors[] = 'Duplicate email within the file (row '.$seen[$email].').';
            }
            $seen[$email] = $line;
            $existingUserId = $existingEmails[$email] ?? null;

            // Employee ID is required + unique for new hires (column is NOT NULL, unique).
            $empId = (string) $r['employee_id'];
            if ($existingUserId === null) {
                if ($empId === '') {
                    $errors[] = 'Employee ID is required for new employees.';
                } elseif (in_array($empId, $existingEmployeeIds, true)) {
                    $errors[] = "Employee ID '{$empId}' already exists.";
                } elseif (isset($seenEmpIds[$empId])) {
                    $errors[] = "Duplicate Employee ID '{$empId}' within the file (row {$seenEmpIds[$empId]}).";
                }
                if ($empId !== '') {
                    $seenEmpIds[$empId] = $line;
                }
            }

            $joiningDate = $this->parseDate($r['joining_date']);
            if ($r['joining_date'] !== '' && $joiningDate === null) {
                $errors[] = 'Joining date is not a valid date.';
            }
            if ($r['joining_date'] === '') {
                $errors[] = 'Joining date is required.';
            }

            $dob = $r['date_of_birth'] !== '' ? $this->parseDate($r['date_of_birth']) : null;
            if ($r['date_of_birth'] !== '' && $dob === null) {
                $errors[] = 'Date of birth is not a valid date.';
            }

            $role = $this->resolveRole($r['role']);
            if ($r['role'] !== '' && $role === null) {
                $errors[] = "Role '{$r['role']}' is not recognised.";
            }

            $status = $this->resolveStatus($r['status']);
            if ($r['status'] !== '' && $status === null) {
                $errors[] = "Status '{$r['status']}' is not recognised.";
            }

            $departmentId = $this->resolveId($departments, $r['department'], 'Department', $errors);
            $jobTitleId = $this->resolveId($jobTitles, $r['designation'], 'Designation', $errors);
            $employmentTypeId = $this->resolveId($employmentTypes, $r['employment_type'], 'Employment type', $errors);
            $shiftId = $this->resolveId($shifts, $r['shift'], 'Shift', $errors);

            $managerId = null;
            if ($r['reporting_manager'] !== '') {
                $managerId = $managers[Str::lower($r['reporting_manager'])] ?? null;
                if ($managerId === null) {
                    $errors[] = "Reporting manager '{$r['reporting_manager']}' was not found (match by email).";
                }
            }

            foreach ([['ifsc', '/^[A-Z]{4}0[A-Z0-9]{6}$/', 'IFSC'], ['pan', '/^[A-Z]{5}[0-9]{4}[A-Z]$/', 'PAN'], ['aadhaar', '/^[0-9]{12}$/', 'Aadhaar']] as [$key, $pattern, $labelText]) {
                if ($r[$key] !== '' && ! preg_match($pattern, strtoupper($r[$key]))) {
                    $errors[] = "{$labelText} format is invalid.";
                }
            }
            if ($r['ctc'] !== '' && ! is_numeric($r['ctc'])) {
                $errors[] = 'CTC must be a number.';
            }

            $status_ = ! empty($errors) ? 'error' : ($existingUserId ? 'update' : 'new');
            $summary[$status_ === 'error' ? 'error' : ($status_ === 'update' ? 'update' : 'new')]++;

            $out[] = [
                'line' => $line,
                'status' => $status_,
                'errors' => $errors,
                'data' => [
                    'name' => $name,
                    'email' => $email,
                    'role' => $role ?? UserRole::Employee,
                    'existing_user_id' => $existingUserId,
                    'password' => (string) $r['password'],
                    'employee' => [
                        'employee_id' => $r['employee_id'] ?: null,
                        'employee_code' => is_numeric($r['biometric_pin']) ? (int) $r['biometric_pin'] : null,
                        'biometric_id' => is_numeric($r['biometric_pin']) ? $r['biometric_pin'] : null,
                        'gender' => $this->resolveGender($r['gender']),
                        'date_of_birth' => $dob,
                        'phone' => $r['phone'] ?: null,
                        'emergency_contact' => $r['emergency_contact'] ?: null,
                        'address' => $r['address'] ?: null,
                        'department_id' => $departmentId,
                        'job_title_id' => $jobTitleId,
                        'manager_id' => $managerId,
                        'employment_type_id' => $employmentTypeId,
                        'shift_id' => $shiftId,
                        'joining_date' => $joiningDate,
                        'status' => $status ?? EmployeeStatus::Active->value,
                    ],
                    'payroll' => [
                        'ctc' => $r['ctc'] !== '' ? (float) $r['ctc'] : null,
                        'pf_number' => $r['pf_number'] ?: null,
                        'esi_number' => $r['esi_number'] ?: null,
                        'pan_number' => $r['pan'] ? strtoupper($r['pan']) : null,
                        'aadhar_number' => $r['aadhaar'] ?: null,
                        'bank_name' => $r['bank_name'] ?: null,
                        'account_number' => $r['account_number'] ?: null,
                        'ifsc_code' => $r['ifsc'] ? strtoupper($r['ifsc']) : null,
                    ],
                ],
            ];
        }

        return ['rows' => $out, 'summary' => $summary];
    }

    /**
     * Persist a parsed preview. Skips error rows; honours skip/update mode for
     * existing employees. All inserts run in one transaction (rollback on error).
     */
    public function import(array $parsed, string $mode, User $actor, ?string $filename = null, bool $sendWelcome = true): EmployeeImportLog
    {
        $mode = $mode === 'update' ? 'update' : 'skip';
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errorLog = [];
        $newlyCreated = [];

        foreach ($parsed['rows'] as $row) {
            if ($row['status'] === 'error') {
                $failed++;
                $errorLog[] = ['row' => $row['line'], 'messages' => $row['errors']];
            }
        }

        try {
            DB::transaction(function () use ($parsed, $mode, $actor, &$imported, &$updated, &$skipped, &$newlyCreated) {
                $passwords = app(PasswordService::class);

                foreach ($parsed['rows'] as $row) {
                    if ($row['status'] === 'error') {
                        continue;
                    }

                    if ($row['status'] === 'update') {
                        if ($mode !== 'update') {
                            $skipped++;

                            continue;
                        }
                        $this->updateExisting($row['data']);
                        $updated++;

                        continue;
                    }

                    // new
                    $plain = $row['data']['password'] ?: $passwords->generate();
                    $user = User::create([
                        'name' => $row['data']['name'],
                        'email' => $row['data']['email'],
                        'password' => Hash::make($plain),
                        'role' => $row['data']['role'],
                    ]);
                    $passwords->recordHistory($user, $user->password, $actor);
                    $user->employee()->create($row['data']['employee']);
                    $user->employee->payrollSettings()->create($row['data']['payroll']);

                    $newlyCreated[] = ['user' => $user, 'plain' => $plain];
                    $imported++;
                }
            });
        } catch (\Throwable $e) {
            // Whole batch rolled back — record as failed.
            return EmployeeImportLog::create([
                'user_id' => $actor->id,
                'filename' => $filename,
                'mode' => $mode,
                'total_rows' => $parsed['summary']['total'] ?? count($parsed['rows']),
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => count($parsed['rows']),
                'errors' => [['row' => 0, 'messages' => ['Import rolled back: '.$e->getMessage()]]],
            ]);
        }

        // Deliver credentials after commit so a rollback never emails a phantom user.
        if ($sendWelcome) {
            foreach ($newlyCreated as $entry) {
                try {
                    Mail::to($entry['user']->email)->send(new WelcomeEmployeeMail($entry['user'], $entry['plain']));
                } catch (\Throwable) {
                    // A mail failure must not fail the import.
                }
            }
        }

        return EmployeeImportLog::create([
            'user_id' => $actor->id,
            'filename' => $filename,
            'mode' => $mode,
            'total_rows' => $parsed['summary']['total'] ?? count($parsed['rows']),
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errorLog ?: null,
        ]);
    }

    private function updateExisting(array $data): void
    {
        $user = User::with('employee.payrollSettings')->find($data['existing_user_id']);
        if (! $user) {
            return;
        }

        $user->update(['name' => $data['name'], 'role' => $data['role']]);

        if ($user->employee) {
            // Never rewrite the immutable, unique employee_id on update.
            $employeeData = $data['employee'];
            unset($employeeData['employee_id']);
            $user->employee->update(array_filter($employeeData, fn ($v) => $v !== null));
            $user->employee->payrollSettings
                ? $user->employee->payrollSettings->update(array_filter($data['payroll'], fn ($v) => $v !== null))
                : $user->employee->payrollSettings()->create($data['payroll']);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return Collection<string, int> lower(name) => id */
    private function lookup($models): Collection
    {
        return $models->mapWithKeys(fn ($m) => [Str::lower((string) $m->name) => $m->id]);
    }

    private function resolveId(Collection $map, string $value, string $label, array &$errors): ?int
    {
        if ($value === '') {
            return null;
        }
        $id = $map[Str::lower($value)] ?? null;
        if ($id === null) {
            $errors[] = "{$label} '{$value}' was not found.";
        }

        return $id;
    }

    private function resolveRole(string $value): ?UserRole
    {
        if ($value === '') {
            return UserRole::Employee;
        }
        $v = Str::lower(trim($value));
        foreach (UserRole::cases() as $case) {
            if (Str::lower($case->value) === $v || Str::lower($case->label()) === $v) {
                return $case;
            }
        }

        return null;
    }

    private function resolveStatus(string $value): ?string
    {
        if ($value === '') {
            return EmployeeStatus::Active->value;
        }
        $v = Str::lower(trim($value));
        foreach (EmployeeStatus::cases() as $case) {
            if (Str::lower($case->value) === $v || Str::lower($case->label()) === $v) {
                return $case->value;
            }
        }

        return null;
    }

    private function resolveGender(string $value): ?string
    {
        $v = Str::lower(trim($value));

        return in_array($v, ['male', 'female', 'other'], true) ? $v : null;
    }

    private function parseDate(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, string> every column key present and string-trimmed */
    private function normalize(array $raw): array
    {
        $out = [];
        foreach (self::COLUMNS as $key) {
            $out[$key] = trim((string) ($raw[$key] ?? ''));
        }

        return $out;
    }

    private function isBlank(array $normalized): bool
    {
        return implode('', $normalized) === '';
    }
}
