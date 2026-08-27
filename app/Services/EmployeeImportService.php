<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeImportLog;
use App\Models\EmploymentType;
use App\Models\JobTitle;
use App\Models\Office;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Leave\LeaveProvisioningService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
    /**
     * Domain used for generated stand-in addresses when the HR sheet has no
     * email. `.local` is reserved and unroutable, so a placeholder can never
     * collide with a real mailbox or accidentally reach someone.
     */
    public const PLACEHOLDER_EMAIL_DOMAIN = 'conexus.local';

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
    public function parse(array $rows, bool $restoreDeleted = false): array
    {
        $departments = $this->lookup(Department::all());
        $jobTitles = $this->lookup(JobTitle::all());
        $employmentTypes = $this->lookup(EmploymentType::all());
        $shifts = $this->lookup(ShiftSetting::all());
        $offices = $this->lookup(Office::all());
        // withTrashed() throughout: a soft-deleted user still occupies its row
        // in `users`, and users_email_unique knows nothing about deleted_at. A
        // scoped lookup made those identities invisible to the importer while
        // MySQL still enforced them, so the row was classified new and the
        // INSERT died on "Duplicate entry ... for key 'users_email_unique'".
        $existingEmails = User::withTrashed()->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [Str::lower((string) $email) => $id]);
        $trashedUserIds = User::onlyTrashed()->pluck('id')->flip();
        $existingByEmployeeId = Employee::withTrashed()->whereNotNull('employee_id')
            ->pluck('user_id', 'employee_id')
            ->mapWithKeys(fn ($userId, $empId) => [Str::lower(trim((string) $empId)) => $userId]);
        // employee_code is the authoritative attendance key, so a clash on it
        // is an identity conflict rather than something to merge through.
        $codeOwners = Employee::withTrashed()->whereNotNull('employee_code')
            ->get(['employee_code', 'employee_id', 'user_id'])
            ->mapWithKeys(fn ($e) => [(int) $e->employee_code => $e]);
        $usersWithEmployee = Employee::withTrashed()->whereNotNull('user_id')->pluck('user_id')->flip();

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

        // Who currently holds each attendance identifier. Both columns are
        // consulted — employee_code is authoritative, biometric_id mirrors it —
        // and soft-deleted rows count, because a deleted employee still holds
        // the value until it is explicitly released.
        $bioCodeOwners = [];
        foreach (Employee::withTrashed()->get(['employee_id', 'user_id', 'employee_code', 'biometric_id']) as $holder) {
            foreach ([$holder->employee_code, $holder->biometric_id] as $identifier) {
                if ($identifier !== null && (string) $identifier !== '') {
                    $bioCodeOwners[(string) $identifier] ??= $holder;
                }
            }
        }

        $seen = [];
        $seenEmpIds = [];
        $seenEmployeeCodes = [];
        $seenBioCodes = [];
        $out = [];
        $summary = ['total' => 0, 'new' => 0, 'update' => 0, 'error' => 0];
        $preflight = [];
        $quality = [
            'duplicate_emails' => 0, 'duplicate_employee_ids' => 0, 'duplicate_employee_codes' => 0,
            'duplicate_bio_codes' => 0,
            'missing_emails' => 0, 'missing_employee_ids' => 0, 'missing_joining_dates' => 0,
            'invalid_dates' => 0,
            'missing_departments' => 0, 'missing_designations' => 0, 'missing_shifts' => 0,
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

            // A missing email no longer blocks the row: generate an unroutable
            // stand-in so the employee still gets a record, and flag it so HR
            // can fill in the real address later.
            $isPlaceholderEmail = false;
            if ($email === '') {
                $email = $this->placeholderEmail($r['employee_id'], $name, $seen, $existingEmails);
                $isPlaceholderEmail = true;
                $warnings[] = "No email address — imported as '{$email}' and flagged Email Pending.";
                $quality['missing_emails']++;
            } elseif (Str::endsWith($email, '@'.self::PLACEHOLDER_EMAIL_DOMAIN)) {
                // Round-trip: exporting employees and re-importing brings the
                // generated address back in the file. It is still a stand-in,
                // so keep the flag rather than silently marking it resolved.
                $isPlaceholderEmail = true;
                $warnings[] = "Still using the generated address '{$email}' — flagged Email Pending.";
                $quality['missing_emails']++;
            } elseif (! Validator::make(['e' => $email], ['e' => 'email'])->passes()) {
                $errors[] = "Email '{$r['email']}' is not a valid address.";
            } elseif (isset($seen[$email])) {
                $errors[] = 'Duplicate email within the file (row '.$seen[$email].').';
                $quality['duplicate_emails']++;
            }
            $seen[$email] = $line;

            // Match on Employee ID first — it is the stable business key, while
            // an email address changes (notably when HR replaces a generated
            // stand-in with the real one). Falling back to email keeps sheets
            // that carry no Employee ID working.
            $empId = (string) $r['employee_id'];
            $existingUserId = ($empId !== '' ? ($existingByEmployeeId[Str::lower($empId)] ?? null) : null)
                ?? ($existingEmails[$email] ?? null);

            // What the preview should show for each half of the identity.
            $userState = $existingUserId ? 'existing' : 'new';
            $employeeState = $existingUserId && $usersWithEmployee->has($existingUserId) ? 'existing' : 'new';

            if ($existingUserId === null) {
                // Creating: Employee ID is required and must be unique.
                if ($empId === '') {
                    $errors[] = "Employee ID is required for new employees — {$who} has none.";
                    $quality['missing_employee_ids']++;
                } elseif (isset($seenEmpIds[$empId])) {
                    $errors[] = "Duplicate Employee ID '{$empId}' within the file (row {$seenEmpIds[$empId]}).";
                    $quality['duplicate_employee_ids']++;
                }
            } elseif ($isPlaceholderEmail === false && ($existingEmails[$email] ?? null) !== null
                && $existingEmails[$email] !== $existingUserId) {
                // The address being assigned already belongs to somebody else;
                // letting this through would hit the users.email unique index.
                $errors[] = "Email '{$email}' is already used by another employee.";
                $quality['duplicate_emails']++;
            }

            // A deleted identity still holds the email at database level.
            // Bringing somebody back is an HR decision, so it happens only when
            // explicitly asked for — never as a side effect of an import that
            // happens to name them.
            if ($existingUserId !== null && $trashedUserIds->has($existingUserId)) {
                $userState = 'deleted';

                if ($restoreDeleted) {
                    $warnings[] = "{$who} was deleted and will be restored, keeping their leave, attendance and payroll history. Their record is then updated from this file.";
                } else {
                    $errors[] = "'{$email}' belongs to a deleted employee record. Tick 'Restore deleted employees' to bring them back with their history intact, or use a different address — an import must not resurrect a deleted identity on its own.";
                    $quality['duplicate_emails']++;
                }
            }

            if ($empId !== '') {
                $seenEmpIds[$empId] = $line;
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

            // A duplicate Bio Code is genuinely corrupting — the biometric sync
            // matches punches on it, so two employees sharing one would collect
            // each other's attendance. This one does block the row.
            $bioCode = $r['biometric_pin'];
            if ($bioCode !== '') {
                if (isset($seenBioCodes[$bioCode])) {
                    $errors[] = "Duplicate Bio Code '{$bioCode}' within the file (row {$seenBioCodes[$bioCode]}) — biometric punches would attach to the wrong employee.";
                    $quality['duplicate_bio_codes']++;
                } elseif (($owner = $bioCodeOwners[$bioCode] ?? null) && $owner->user_id !== $existingUserId) {
                    // Naming the holder matters: "already assigned" leaves HR
                    // hunting through the directory for which record to fix.
                    $errors[] = "Bio Code '{$bioCode}' already belongs to {$owner->employee_id}. Two employees cannot share an attendance code.";
                    $quality['duplicate_bio_codes']++;
                }
                $seenBioCodes[$bioCode] = $line;
            }

            // A supplied-but-unparseable date is still an error (it means the
            // sheet is wrong); a genuinely blank one imports and is flagged, so
            // incomplete HR records don't block the migration.
            $joiningDate = $this->parseDate($r['joining_date']);
            if ($r['joining_date'] !== '' && $joiningDate === null) {
                $errors[] = "Joining date '{$r['joining_date']}' is not a valid date.";
                $quality['invalid_dates']++;
            }
            if ($r['joining_date'] === '') {
                $warnings[] = "No joining date — imported and flagged Joining Date Missing. Probation, leave accrual and payroll proration stay off for {$who} until it is set.";
                $quality['missing_joining_dates']++;
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

            if ($departmentId === null) {
                $quality['missing_departments']++;
            }
            if ($jobTitleId === null) {
                $quality['missing_designations']++;
            }
            if ($shiftId === null) {
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

            // What this row's leave will be, shown before anything is written.
            // Annual leave is calculated from the policy and the working
            // pattern, so the preview has to say which policy, which pattern
            // and how many days — otherwise HR approves a number they cannot
            // see. Nothing is created here.
            $leave = $status_ === 'new' ? $this->previewLeave($r) : null;

            if ($leave !== null) {
                foreach ($leave['issues'] as $issue) {
                    $warnings[] = $issue;
                }
            }

            $out[] = [
                'line' => $line,
                'status' => $status_,
                'errors' => $errors,
                'warnings' => $warnings,
                'leave' => $leave,
                'data' => [
                    'name' => $name,
                    'email' => $email,
                    'role' => $role ?? UserRole::Employee,
                    'existing_user_id' => $existingUserId,
                    'user_state' => $userState,
                    'restore' => $userState === 'deleted' && $restoreDeleted,
                    'employee_state' => $employeeState,
                    'password' => (string) $r['password'],
                    // Kept so import() can re-resolve managers that are themselves
                    // rows in this same file, once every row has been created.
                    'manager_ref' => $r['reporting_manager'],
                    // Raw master-data names, so import() can create any that are
                    // missing when auto-create is enabled and then re-resolve.
                    'master_refs' => [
                        'department' => $r['department'],
                        'designation' => $r['designation'],
                        'shift' => $r['shift'],
                        'company' => $r['company'],
                    ],
                    'employee' => [
                        'employee_id' => $r['employee_id'] ?: null,
                        'employee_code' => is_numeric($employeeCode) ? (int) $employeeCode : null,
                        'has_placeholder_email' => $isPlaceholderEmail,
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
     *
     * @param  bool  $autoCreateMasterData  create any departments/designations/
     *                                      shifts/offices the file references but
     *                                      the system doesn't have yet
     */
    public function import(
        array $parsed,
        string $mode,
        User $actor,
        ?string $filename = null,
        bool $autoCreateMasterData = false,
    ): EmployeeImportLog {
        $mode = $mode === 'update' ? 'update' : 'skip';
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errorLog = [];
        $newlyCreated = [];
        $createdMasterData = [];

        foreach ($parsed['rows'] as $row) {
            if ($row['status'] === 'error') {
                $failed++;
                $errorLog[] = ['row' => $row['line'], 'messages' => $row['errors']];
            }
        }

        try {
            DB::transaction(function () use ($parsed, $mode, $actor, $autoCreateMasterData, &$imported, &$updated, &$skipped, &$newlyCreated, &$createdMasterData) {
                $passwords = app(PasswordService::class);

                if ($autoCreateMasterData) {
                    [$parsed, $createdMasterData] = $this->autoCreateMasterData($parsed);
                }

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

        // Import does not hand out credentials.
        //
        // An imported row may be half-finished, may carry a generated
        // placeholder address, may be a duplicate of somebody already here.
        // Access is granted afterwards, per employee, by an HR admin who has
        // looked at the record: see EmployeeInvitationService.

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
        // withTrashed(): a restore row targets a soft-deleted user, which a
        // scoped find() would return as null — silently doing nothing.
        $user = User::withTrashed()->with('employee.payrollSettings')->find($data['existing_user_id']);
        if (! $user) {
            return;
        }

        if (! empty($data['restore'])) {
            $this->restoreIdentity($user);
        }

        // The email is updatable now that rows match on Employee ID — this is
        // how HR replaces a generated stand-in address with the real one.
        //
        // Role is deliberately NOT updated. A spreadsheet is the wrong place to
        // grant or revoke privileges: a stale or mistyped column could promote
        // someone to HR, or demote a director, with no approval step and no
        // visible trace. Role changes belong to the employee edit screen, which
        // is permission-gated and audit-logged. The password is likewise never
        // touched here — an import must not invalidate a working login.
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        if ($user->employee) {
            // Never rewrite the immutable, unique employee_id on update.
            $employeeData = $data['employee'];
            unset($employeeData['employee_id']);
            $user->employee->update(array_filter($employeeData, fn ($v) => $v !== null));
            $user->employee->payrollSettings
                ? $user->employee->payrollSettings->update(array_filter($data['payroll'], fn ($v) => $v !== null))
                : $user->employee->payrollSettings()->create($data['payroll']);

            return;
        }

        // A user with no employee record — someone onboarded as a login before
        // their HR record existed. The account is reused rather than a second
        // one created for the same person, which is what the email unique index
        // would refuse anyway.
        // Hold the new record directly: `$user->employee` was eager-loaded as
        // null above, and the relation stays cached that way.
        $employee = $user->employee()->create($data['employee']);
        $employee->payrollSettings()->create($data['payroll']);
    }

    /**
     * Bring a deleted employee back, with their history.
     *
     * Soft-deleting released the biometric code and hid both records; the
     * employee's leave, attendance, payslips and audit trail were never
     * removed. Restoring reconnects them rather than starting the person again
     * as a stranger with the same name.
     *
     * Audited explicitly: a resurrection is exactly the kind of change someone
     * will want to account for later.
     */
    private function restoreIdentity(User $user): void
    {
        if ($user->trashed()) {
            $user->restore();
        }

        $employee = Employee::withTrashed()->where('user_id', $user->id)->first();

        if ($employee?->trashed()) {
            $employee->restore();
            AuditLog::record($employee, 'restored', null, ['restored_by' => 'employee import']);
        }

        // The relation was loaded while both were hidden.
        $user->unsetRelation('employee');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return Collection<string, int> lower(trimmed name) => id
     *
     * Names are trimmed on BOTH sides of the comparison: HR sheets routinely
     * carry trailing spaces ("UK Operations "), and so does master data that
     * was itself created from such a sheet.
     */
    /**
     * The shift whose hours a label describes, regardless of what it is called.
     *
     * "10.30 AM to 7.30 PM" and "IT Shift" are the same shift when both run
     * 10:30 to 19:30; only one of them should exist, and this is how a
     * spreadsheet written in the other form still finds it.
     */
    private function shiftIdBySchedule(string $label): ?int
    {
        [$start, $end] = $this->parseShiftLabel($label);

        if (! $start || ! $end) {
            return null;
        }

        return ShiftSetting::whereTime('start_time', $start)
            ->whereTime('end_time', $end)
            ->value('id');
    }

    /**
     * The leave a new row would receive, without creating anything.
     *
     * Built on an unsaved Employee so the entitlement engine sees the working
     * pattern the file supplies rather than a default. Existing employees are
     * skipped: their policy and balances are not the importer's to restate.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function previewLeave(array $row): array
    {
        // The file's own pattern wins. Failing that, the approved company
        // default fills it — a stated policy, not an assumption. With neither,
        // the pattern stays unset and provisioning reports the gap rather than
        // producing an entitlement nobody verified.
        $daysPerWeek = $this->numericOrNull($row['working_days_per_week'] ?? null)
            ?? $this->numericOrNull(config('leave_provisioning.default_working_days_per_week'));

        $employee = new Employee([
            'working_pattern' => 'regular',
            'working_days_per_week' => $daysPerWeek,
            'joining_date' => $row['joining_date'] ?? null,
        ]);

        $preview = app(LeaveProvisioningService::class)->preview($employee);

        return [
            'policy' => $preview['policy_name'],
            'pattern' => $preview['pattern'],
            'pattern_verified' => $preview['pattern_verified'],
            'annual_leave' => $preview['entitlement'],
            'carry_forward' => $preview['carry_forward'],
            'issues' => $preview['issues'],
        ];
    }

    private function numericOrNull(mixed $value): ?float
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '' || ! is_numeric($value)) ? null : (float) $value;
    }

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
     * Create any departments, designations, shifts or offices the file refers
     * to that don't exist yet, then re-resolve every row's ids against them.
     * Runs inside the import transaction, so a later failure rolls the new
     * master data back too.
     *
     * Shift hours are read out of the HR label where possible — "10.30 AM to
     * 7.30 PM" and "1PM to 10PM" both parse — falling back to the company's
     * default window when the label carries no times ("General Shift").
     *
     * @return array{0: array, 1: array<string, array<int, string>>} [patched parse result, what was created]
     */
    private function autoCreateMasterData(array $parsed): array
    {
        $created = [];
        // departments/job_titles/offices are all NOT NULL on company_id. On a
        // fresh system with no company yet, creating one is far better than
        // letting a constraint violation roll back the whole import.
        $companyId = Company::query()->value('id')
            ?? Company::create(['name' => config('app.name', 'Company')])->id;

        foreach ($parsed['rows'] as $row) {
            if ($row['status'] === 'error') {
                continue;
            }

            foreach ($row['data']['master_refs'] ?? [] as $kind => $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }

                $model = match ($kind) {
                    'department' => Department::class,
                    'designation' => JobTitle::class,
                    'shift' => ShiftSetting::class,
                    'company' => Office::class,
                    default => null,
                };

                if ($model === null) {
                    continue;
                }

                $exists = $model::query()->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower($name)])->exists();
                if ($exists) {
                    continue;
                }

                if ($kind === 'shift') {
                    [$start, $end] = $this->parseShiftLabel($name);

                    // A shift is its hours, not its label. The name check above
                    // only catches a repeat of the same wording — it let
                    // "10.30 AM to 7.30 PM" become a second shift alongside
                    // "IT Shift" despite both being 10:30 to 19:30, and every
                    // employee on that spreadsheet column landed on the copy.
                    $sameSchedule = $start && $end
                        ? ShiftSetting::whereTime('start_time', $start)->whereTime('end_time', $end)->first()
                        : null;

                    if ($sameSchedule) {
                        continue;
                    }

                    ShiftSetting::create(['name' => $name, 'start_time' => $start, 'end_time' => $end]);
                } else {
                    $model::create(['name' => $name, 'company_id' => $companyId]);
                }

                $created[$kind][] = $name;
            }
        }

        // Re-resolve every row against the now-complete master data.
        $maps = [
            'department' => ['map' => $this->lookup(Department::all()), 'key' => 'department_id'],
            'designation' => ['map' => $this->lookup(JobTitle::all()), 'key' => 'job_title_id'],
            'shift' => ['map' => $this->lookup(ShiftSetting::all()), 'key' => 'shift_id'],
            'company' => ['map' => $this->lookup(Office::all()), 'key' => 'office_id'],
        ];

        foreach ($parsed['rows'] as $i => $row) {
            foreach ($maps as $kind => $spec) {
                $name = trim((string) ($row['data']['master_refs'][$kind] ?? ''));
                if ($name === '') {
                    continue;
                }
                $resolved = $spec['map'][Str::lower($name)] ?? null;

                // A shift cell that spells out its hours rather than naming a
                // shift still has to reach the shift that keeps those hours.
                // Without this the row imports with no shift at all, which is
                // how the duplicate came to look necessary in the first place.
                if ($resolved === null && $kind === 'shift') {
                    $resolved = $this->shiftIdBySchedule($name);
                }

                $parsed['rows'][$i]['data']['employee'][$spec['key']] = $resolved;
            }
        }

        foreach ($created as $kind => $names) {
            $created[$kind] = collect($names)->unique()->sort()->values()->all();
        }

        return [$parsed, $created];
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
     * Read start/end times out of an HR shift label. Handles the forms real
     * sheets use — "10.30 AM to 7.30 PM", "1PM to 10PM", "9:00 AM - 6:00 PM",
     * "10:30-19:30" — and falls back to a standard 09:00–18:00 day for labels
     * that carry no times at all, which HR then corrects in Settings.
     *
     * @return array{0: string, 1: string}
     */
    private function parseShiftLabel(string $label): array
    {
        // "10.30 AM" → "10:30 AM"; a dot separator confuses date parsing.
        $normalised = preg_replace('/(\d)\.(\d)/', '$1:$2', trim($label)) ?? $label;

        if (preg_match('/^(.*?)\s*(?:to|-|–|—)\s*(.*)$/i', $normalised, $m)) {
            try {
                return [
                    Carbon::parse(trim($m[1]))->format('H:i:s'),
                    Carbon::parse(trim($m[2]))->format('H:i:s'),
                ];
            } catch (\Throwable) {
                // Fall through to the default window.
            }
        }

        return ['09:00:00', '18:00:00'];
    }

    /**
     * Build an unroutable stand-in address for a row with no email, keyed on
     * the Employee ID so it is stable across re-imports (re-importing the same
     * sheet matches the same person rather than creating a duplicate).
     * A numeric suffix is added only if that address is somehow already taken.
     *
     * @param  array<string, int>  $seenInFile
     * @param  Collection<string, int>  $existingEmails
     */
    private function placeholderEmail(string $employeeId, string $name, array $seenInFile, Collection $existingEmails): string
    {
        $local = Str::slug($employeeId !== '' ? $employeeId : $name, '.');
        $local = $local !== '' ? $local : 'employee';

        $candidate = Str::lower($local).'@'.self::PLACEHOLDER_EMAIL_DOMAIN;
        $suffix = 1;

        while (isset($seenInFile[$candidate]) || $existingEmails->has($candidate)) {
            $candidate = Str::lower($local).'-'.(++$suffix).'@'.self::PLACEHOLDER_EMAIL_DOMAIN;
        }

        return $candidate;
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
