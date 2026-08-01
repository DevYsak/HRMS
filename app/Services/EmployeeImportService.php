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
use App\Models\NotificationSetting;
use App\Models\Office;
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
        'employee_id', 'employee_code', 'first_name', 'middle_name', 'last_name', 'email', 'gender',
        'date_of_birth', 'phone', 'emergency_contact', 'address', 'company', 'department',
        'designation', 'role', 'reporting_manager', 'joining_date', 'employment_type',
        'shift', 'status', 'biometric_pin', 'ctc', 'pf_number', 'esi_number', 'pan',
        'aadhaar', 'bank_name', 'account_number', 'ifsc', 'password',
    ];

    /**
     * Headings HR spreadsheets use in the wild, mapped onto the canonical
     * COLUMNS keys above. Lets a live HR sheet import as-is instead of forcing
     * someone to rename columns before every run. A canonical column already
     * present in the file always wins over its alias.
     *
     * `employee_name` is a virtual target: a single full-name column is split
     * into first/middle/last by normalize().
     */
    public const HEADER_ALIASES = [
        'bio_code' => 'biometric_pin',
        'biometric_code' => 'biometric_pin',
        'bio_id' => 'biometric_pin',
        'name' => 'employee_name',
        'full_name' => 'employee_name',
        'employee_full_name' => 'employee_name',
        'manager' => 'reporting_manager',
        'reporting_manager_email' => 'reporting_manager',
        'date_of_joining' => 'joining_date',
        'doj' => 'joining_date',
        'date_of_birth' => 'date_of_birth',
        'dob' => 'date_of_birth',
        'mobile' => 'phone',
        'mobile_number' => 'phone',
        'contact_number' => 'phone',
        'employment_status' => 'status',
        'company_branch' => 'company',
        'branch' => 'company',
        'office' => 'company',
        'emp_id' => 'employee_id',
        'emp_code' => 'employee_code',
    ];

    /** Human-readable headings for the downloadable template (slug == COLUMNS). */
    public function templateHeadings(): array
    {
        return [
            'Employee ID', 'Employee Code', 'First Name', 'Middle Name', 'Last Name', 'Email', 'Gender',
            'Date Of Birth', 'Phone', 'Emergency Contact', 'Address', 'Company', 'Department',
            'Designation', 'Role', 'Reporting Manager', 'Joining Date', 'Employment Type',
            'Shift', 'Status', 'Biometric PIN', 'CTC', 'PF Number', 'ESI Number', 'PAN',
            'Aadhaar', 'Bank Name', 'Account Number', 'IFSC', 'Password',
        ];
    }

    /** A single illustrative sample row for the template. */
    public function sampleRow(): array
    {
        return [
            'EMP1001', '1001', 'Asha', '', 'Verma', 'asha.verma@example.com', 'female',
            '1994-05-12', '9876543210', '9876500000', '12 MG Road, Pune', 'Head Office', 'Engineering',
            'Software Engineer', 'employee', 'manager@example.com', '2026-07-01', 'Full Time',
            'IT Shift', 'active', '101', '600000', 'MH/BAN/12345/123', '31000000000000000',
            'ABCDE1234F', '123456789012', 'HDFC Bank', '50100000000000', 'HDFC0001234', '',
        ];
    }

    /**
     * Validate + resolve raw rows into a preview.
     *
     * Returns three blocks: `rows` (per-row result), `summary` (the new/update/
     * error tally), `preflight` (master data referenced by the file but missing
     * from the database — shown before importing rather than as N warnings
     * after), and `quality` (the data-quality counters HR needs to sign off).
     *
     * @param  array<int, array<string, mixed>>  $rows  keyed by slugged heading
     * @return array{rows: array<int, array{line:int, data:array, status:string, errors:array<int,string>}>, summary: array<string,int>, preflight: array<string, array<int,string>>, quality: array<string,int>}
     */
    public function parse(array $rows): array
    {
        $departments = $this->lookup(Department::all());
        $jobTitles = $this->lookup(JobTitle::all());
        $employmentTypes = $this->lookup(EmploymentType::all());
        $shifts = $this->lookup(ShiftSetting::all());
        $offices = $this->lookup(Office::all());
        $existingEmails = User::query()->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [Str::lower((string) $email) => $id]);
        $existingEmployeeIds = Employee::query()->pluck('employee_id')->filter()->map(fn ($v) => (string) $v)->all();

        // Manager can be given as an email, an Employee ID, or a plain name —
        // HR sheets in the wild use all three.
        $managersByEmail = $existingEmails;
        $managersByEmployeeId = Employee::query()->whereNotNull('employee_id')
            ->pluck('user_id', 'employee_id')
            ->mapWithKeys(fn ($userId, $empId) => [Str::lower(trim((string) $empId)) => $userId]);
        $usersByName = User::query()->get(['id', 'name'])
            ->groupBy(fn (User $u) => Str::lower(trim((string) $u->name)));

        // Identities carried by the file itself. On a first migration the whole
        // org is new, so a manager is usually another row in the same file —
        // those are linked in a second pass after import rather than warned about.
        $inFileIdentities = [];
        foreach ($rows as $raw) {
            $n = $this->normalize($raw);
            foreach ([$n['email'], $n['employee_id'], trim(implode(' ', array_filter([$n['first_name'], $n['middle_name'], $n['last_name']])))] as $identity) {
                if (trim((string) $identity) !== '') {
                    $inFileIdentities[Str::lower(trim((string) $identity))] = true;
                }
            }
        }

        $seen = [];
        $seenEmpIds = [];
        $seenEmployeeCodes = [];
        $out = [];
        $summary = ['total' => 0, 'new' => 0, 'update' => 0, 'error' => 0];
        $preflight = [];
        $quality = [
            'duplicate_emails' => 0, 'duplicate_employee_ids' => 0, 'duplicate_employee_codes' => 0,
            'missing_emails' => 0, 'missing_employee_ids' => 0,
            'invalid_dates' => 0, 'missing_shifts' => 0,
        ];

        foreach ($rows as $i => $raw) {
            $r = $this->normalize($raw);
            if ($this->isBlank($r)) {
                continue;
            }

            $summary['total']++;
            $line = $i + 2; // +1 for zero-index, +1 for the heading row
            $errors = [];   // block the row
            $warnings = []; // row still imports; the unmatched field is left blank

            $email = Str::lower((string) $r['email']);
            $name = trim(implode(' ', array_filter([$r['first_name'], $r['middle_name'], $r['last_name']])));
            // Used to make every message point at a human, not just a row number.
            $who = $name !== '' ? "'{$name}'" : ($r['employee_id'] !== '' ? "'{$r['employee_id']}'" : 'this row');

            if ($name === '') {
                $errors[] = 'First name is required.';
            }
            if ($email === '') {
                $errors[] = "Email is required — {$who} has no email address. It is needed to create their login.";
                $quality['missing_emails']++;
            } elseif (! Validator::make(['e' => $email], ['e' => 'email'])->passes()) {
                $errors[] = "Email '{$r['email']}' is not a valid address.";
            } elseif (isset($seen[$email])) {
                $errors[] = 'Duplicate email within the file (row '.$seen[$email].').';
                $quality['duplicate_emails']++;
            }
            $seen[$email] = $line;
            $existingUserId = $existingEmails[$email] ?? null;

            // Employee ID is required + unique for new hires (column is NOT NULL, unique).
            $empId = (string) $r['employee_id'];
            if ($existingUserId === null) {
                if ($empId === '') {
                    $errors[] = "Employee ID is required for new employees — {$who} has none.";
                    $quality['missing_employee_ids']++;
                } elseif (in_array($empId, $existingEmployeeIds, true)) {
                    $errors[] = "Employee ID '{$empId}' already exists.";
                    $quality['duplicate_employee_ids']++;
                } elseif (isset($seenEmpIds[$empId])) {
                    $errors[] = "Duplicate Employee ID '{$empId}' within the file (row {$seenEmpIds[$empId]}).";
                    $quality['duplicate_employee_ids']++;
                }
                if ($empId !== '') {
                    $seenEmpIds[$empId] = $line;
                }
            }

            // Employee Code is its own column now; biometric_pin remains the
            // device mapping and is only used as a fallback for older sheets.
            $employeeCode = $r['employee_code'] !== '' ? $r['employee_code'] : $r['biometric_pin'];
            if ($employeeCode !== '' && ! is_numeric($employeeCode)) {
                $errors[] = "Employee Code '{$employeeCode}' must be a number.";
            } elseif ($employeeCode !== '') {
                if (isset($seenEmployeeCodes[$employeeCode])) {
                    $errors[] = "Duplicate Employee Code '{$employeeCode}' within the file (row {$seenEmployeeCodes[$employeeCode]}).";
                    $quality['duplicate_employee_codes']++;
                }
                $seenEmployeeCodes[$employeeCode] = $line;
            }

            $joiningDate = $this->parseDate($r['joining_date']);
            if ($r['joining_date'] !== '' && $joiningDate === null) {
                $errors[] = "Joining date '{$r['joining_date']}' is not a valid date.";
                $quality['invalid_dates']++;
            }
            if ($r['joining_date'] === '') {
                $errors[] = "Joining date is required — {$who} has none.";
            }

            $dob = $r['date_of_birth'] !== '' ? $this->parseDate($r['date_of_birth']) : null;
            if ($r['date_of_birth'] !== '' && $dob === null) {
                $errors[] = "Date of birth '{$r['date_of_birth']}' is not a valid date.";
                $quality['invalid_dates']++;
            }

            $role = $this->resolveRole($r['role']);
            if ($r['role'] !== '' && $role === null) {
                $errors[] = "Role '{$r['role']}' is not recognised.";
            }

            $status = $this->resolveStatus($r['status']);
            if ($r['status'] !== '' && $status === null) {
                $errors[] = "Status '{$r['status']}' is not recognised.";
            }

            // Optional look-ups: unmatched values warn (and import blank) rather than block the row.
            $departmentId = $this->resolveId($departments, $r['department'], 'Department', $warnings, $preflight);
            $jobTitleId = $this->resolveId($jobTitles, $r['designation'], 'Designation', $warnings, $preflight);
            $employmentTypeId = $this->resolveId($employmentTypes, $r['employment_type'], 'Employment type', $warnings, $preflight);
            $shiftId = $this->resolveId($shifts, $r['shift'], 'Shift', $warnings, $preflight);
            $officeId = $this->resolveId($offices, $r['company'], 'Company/Branch', $warnings, $preflight);

            if ($r['shift'] === '' || $shiftId === null) {
                $quality['missing_shifts']++;
            }

            $managerId = $this->resolveManager(
                $r['reporting_manager'],
                $managersByEmail,
                $managersByEmployeeId,
                $usersByName,
                $warnings,
                $inFileIdentities,
            );

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
                'warnings' => $warnings,
                'data' => [
                    'name' => $name,
                    'email' => $email,
                    'role' => $role ?? UserRole::Employee,
                    'existing_user_id' => $existingUserId,
                    'password' => (string) $r['password'],
                    // Kept so import() can re-resolve managers that are themselves
                    // rows in this same file, once every row has been created.
                    'manager_ref' => $r['reporting_manager'],
                    'employee' => [
                        'employee_id' => $r['employee_id'] ?: null,
                        'employee_code' => is_numeric($employeeCode) ? (int) $employeeCode : null,
                        'biometric_id' => is_numeric($r['biometric_pin']) ? $r['biometric_pin'] : null,
                        'gender' => $this->resolveGender($r['gender']),
                        'date_of_birth' => $dob,
                        'phone' => $r['phone'] ?: null,
                        'emergency_contact' => $r['emergency_contact'] ?: null,
                        'address' => $r['address'] ?: null,
                        'office_id' => $officeId,
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

        // De-duplicate and sort the pre-flight lists so the preview shows each
        // missing master record once, not once per row that referenced it.
        foreach ($preflight as $label => $values) {
            $preflight[$label] = collect($values)->unique()->sort()->values()->all();
        }
        ksort($preflight);

        return ['rows' => $out, 'summary' => $summary, 'preflight' => $preflight, 'quality' => $quality];
    }

    /**
     * Persist a parsed preview. Skips error rows; honours skip/update mode for
     * existing employees. All inserts run in one transaction (rollback on error).
     */
    public function import(array $parsed, string $mode, User $actor, ?string $filename = null, bool $sendWelcome = false): EmployeeImportLog
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

                // Now that every row exists, link managers who were themselves
                // rows in this file (a first migration imports the whole org at once).
                $this->linkManagers($parsed['rows']);
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
        // Only when explicitly opted-in AND the Welcome Email is enabled in
        // Settings > Notifications & Email (a disabled toggle wins).
        $welcomeEnabled = NotificationSetting::for(WelcomeEmployeeMail::class)?->mail_enabled ?? true;

        if ($sendWelcome && $welcomeEnabled) {
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

    /**
     * @return Collection<string, int> lower(trimmed name) => id
     *
     * Names are trimmed on BOTH sides of the comparison: HR sheets routinely
     * carry trailing spaces ("UK Operations "), and so does master data that
     * was itself created from such a sheet.
     */
    private function lookup($models): Collection
    {
        return $models->mapWithKeys(fn ($m) => [Str::lower(trim((string) $m->name)) => $m->id]);
    }

    /**
     * Resolve a master-data name to its id. An unmatched value warns (the row
     * still imports with the field blank) and is recorded in $preflight so the
     * preview can list everything missing up front.
     *
     * @param  array<string, array<int, string>>  $preflight
     */
    private function resolveId(Collection $map, string $value, string $label, array &$warnings, array &$preflight): ?int
    {
        if ($value === '') {
            return null;
        }
        $id = $map[Str::lower(trim($value))] ?? null;
        if ($id === null) {
            $warnings[] = "{$label} '{$value}' was not found.";
            $preflight[$label][] = $value;
        }

        return $id;
    }

    /**
     * Resolve a reporting manager given an email, an Employee ID, or a plain
     * name — HR sheets use all three. Ambiguous names (two people with the
     * same name) warn rather than silently picking one.
     *
     * @param  Collection<string, int>  $byEmail
     * @param  Collection<string, int>  $byEmployeeId
     * @param  Collection<string, Collection<int, User>>  $byName
     */
    private function resolveManager(
        string $value,
        Collection $byEmail,
        Collection $byEmployeeId,
        Collection $byName,
        array &$warnings,
        array $inFileIdentities = [],
    ): ?int {
        if ($value === '') {
            return null;
        }

        $key = Str::lower(trim($value));

        if ($id = $byEmail[$key] ?? null) {
            return (int) $id;
        }

        if ($id = $byEmployeeId[$key] ?? null) {
            return (int) $id;
        }

        $matches = $byName[$key] ?? collect();
        if ($matches->count() === 1) {
            return (int) $matches->first()->id;
        }
        if ($matches->count() > 1) {
            $warnings[] = "Reporting manager '{$value}' matches {$matches->count()} people — set the manager's email or Employee ID instead (left blank).";

            return null;
        }

        // The manager is another row in this file — it will link automatically
        // once the whole batch has been created, so this isn't worth warning about.
        if (isset($inFileIdentities[$key])) {
            return null;
        }

        $warnings[] = "Reporting manager '{$value}' was not found (left blank).";

        return null;
    }

    /**
     * Second pass, run inside the import transaction once every row exists:
     * links reporting managers that were themselves rows in the same file.
     * Without this, a first-time migration (where the whole org is new) would
     * leave every manager blank and need a second import to fix.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return int number of employees whose manager was linked
     */
    private function linkManagers(array $rows): int
    {
        $refs = collect($rows)
            ->filter(fn ($row) => $row['status'] !== 'error' && trim((string) ($row['data']['manager_ref'] ?? '')) !== '')
            ->filter(fn ($row) => $row['data']['employee']['manager_id'] === null);

        if ($refs->isEmpty()) {
            return 0;
        }

        $byEmail = User::query()->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [Str::lower((string) $email) => $id]);
        $byEmployeeId = Employee::query()->whereNotNull('employee_id')
            ->pluck('user_id', 'employee_id')
            ->mapWithKeys(fn ($userId, $empId) => [Str::lower(trim((string) $empId)) => $userId]);
        $byName = User::query()->get(['id', 'name'])
            ->groupBy(fn (User $u) => Str::lower(trim((string) $u->name)));

        $linked = 0;
        $ignored = [];

        foreach ($refs as $row) {
            $employee = Employee::whereHas('user', fn ($q) => $q->where('email', $row['data']['email']))->first();
            if (! $employee) {
                continue;
            }

            $managerId = $this->resolveManager((string) $row['data']['manager_ref'], $byEmail, $byEmployeeId, $byName, $ignored);
            if ($managerId !== null) {
                $employee->update(['manager_id' => $managerId]);
                $linked++;
            }
        }

        return $linked;
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

    /**
     * Parse the date formats HR sheets actually contain ('2026-07-01',
     * '07-06-2010', '11th Feb 2025', '23rd Sept 2024') and reject the ones
     * PHP would otherwise silently corrupt:
     *  - a bare number ('2026') becomes today's date with that year;
     *  - an impossible date ('31-02-2025') rolls over to 3 March.
     * Both are returned as null so the caller reports a blocking row error.
     */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // A digits-only value carries no month/day and can only be guessed at.
        if (preg_match('/^\d+$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // Carbon/DateTime reports day-overflow ("31 Feb") as a warning rather
        // than an exception, so it has to be checked explicitly.
        $errors = Carbon::getLastErrors();
        if ($errors && ($errors['warning_count'] ?? 0) > 0) {
            return null;
        }

        return $date->toDateString();
    }

    /** @return array<string, string> every column key present and string-trimmed */
    private function normalize(array $raw): array
    {
        // Fold HR's own heading names onto the canonical keys. A canonical
        // column that already carries a value is never overwritten.
        foreach (self::HEADER_ALIASES as $alias => $canonical) {
            if (trim((string) ($raw[$canonical] ?? '')) !== '') {
                continue;
            }
            if (trim((string) ($raw[$alias] ?? '')) !== '') {
                $raw[$canonical] = $raw[$alias];
            }
        }

        $out = [];
        foreach (self::COLUMNS as $key) {
            $out[$key] = trim((string) ($raw[$key] ?? ''));
        }

        // A sheet with a single "Employee Name" column is split into the
        // first/middle/last the template expects.
        $fullName = trim((string) ($raw['employee_name'] ?? ''));
        if ($fullName !== '' && $out['first_name'] === '' && $out['last_name'] === '') {
            [$out['first_name'], $out['middle_name'], $out['last_name']] = $this->splitName($fullName);
        }

        return $out;
    }

    /**
     * Split a full name into [first, middle, last]. "Gayatri Chagan Navlakhe"
     * becomes first=Gayatri, middle=Chagan, last=Navlakhe; a single-word name
     * stays entirely in first.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $first = (string) array_shift($parts);
        $last = $parts !== [] ? (string) array_pop($parts) : '';
        $middle = implode(' ', $parts);

        return [$first, $middle, $last];
    }

    private function isBlank(array $normalized): bool
    {
        return implode('', $normalized) === '';
    }
}
