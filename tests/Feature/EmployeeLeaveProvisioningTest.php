<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\EmployeeImportService;
use App\Services\Leave\LeaveProvisioningService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Support\Str;

/**
 * What a new employee starts with.
 *
 * Onboarding seeded a flat allocation per leave type keyed on now()->year — a
 * calendar year, in a company whose leave year runs 1 July to 30 June — and
 * assigned no leave policy at all. That is why the UK entitlement engine was
 * built, correct, and connected to nobody: every employee reached it with
 * leave_policy_id null and fell straight back to per-type day counts.
 *
 * Annual leave is now calculated. Where the data to calculate it is missing,
 * provisioning stops and says so, because an entitlement resting on an assumed
 * working pattern is a guess with a number in front of it.
 */
function elpYears(): array
{
    return [
        LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
        LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
    ];
}

function elpUkPolicy(): LeavePolicy
{
    return LeavePolicy::firstOrCreate(
        ['name' => 'UK Standard'],
        [
            'statutory_weeks' => 5.60,
            'contractual_additional_weeks' => 0,
            'bank_holiday_treatment' => 'additional',
            'max_carry_over_days' => null,
            'irregular_accrual_rate' => 0.1207,
            'is_default' => true,
            'is_active' => true,
        ]
    );
}

function elpAnnualType(): LeaveType
{
    return LeaveType::firstOrCreate(
        ['code' => 'AL'],
        [
            'name' => 'Annual Leave',
            'category' => 'annual',
            // Deliberately absent: entitlement comes from the policy.
            'annual_allocation_days' => null,
            'allow_carry_forward' => true,
            'carry_forward_mode' => 'hr_approval',
        ]
    );
}

/** A new hire with a verified pattern, created through the normal path. */
function elpHire(int $daysPerWeek = 5, array $overrides = []): Employee
{
    return Employee::factory()->create(array_merge([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'leave_policy_id' => null,
        'working_pattern' => 'regular',
        'working_days_per_week' => $daysPerWeek,
    ], $overrides));
}

// ── 1, 2, 3. Policy, leave year, entitlement ───────────────────────────────

test('the approved company default supplies a missing pattern', function () {
    // Stated by the business, so it is a policy rather than an assumption —
    // and the entitlement it produces is not flagged as guessed.
    config(['leave_provisioning.default_working_days_per_week' => 5]);
    elpYears();
    elpUkPolicy();
    elpAnnualType();

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'working_days_per_week' => null,
        'working_days' => null,
    ]);

    $preview = app(LeaveProvisioningService::class)->preview($employee->fresh());

    expect($preview['pattern_verified'])->toBeTrue()
        ->and($preview['entitlement'])->toBe(28.0)
        ->and($preview['issues'])->toBeEmpty();
});

test('a new employee is given the default policy', function () {
    elpYears();
    elpUkPolicy();
    elpAnnualType();

    $employee = elpHire();

    expect($employee->fresh()->leave_policy_id)->toBe(elpUkPolicy()->id);
});

test('a new employee is provisioned into the current leave year', function () {
    [, $curr] = elpYears();
    elpUkPolicy();
    $type = elpAnnualType();

    $employee = elpHire();

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->first();

    expect($balance)->not->toBeNull()
        ->and((int) $balance->year)->toBe($curr->legacyYear())
        // Linked by identity, not only the legacy integer.
        ->and($balance->leave_year_id)->toBe($curr->id);
});

test('a five day employee receives 28 days', function () {
    elpYears();
    elpUkPolicy();
    $type = elpAnnualType();

    $employee = elpHire(daysPerWeek: 5);

    expect((float) LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->value('allocated_days'))->toBe(28.0);
});

test('a four day employee receives 22.4 days', function () {
    elpYears();
    elpUkPolicy();
    $type = elpAnnualType();

    $employee = elpHire(daysPerWeek: 4);

    expect((float) LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->value('allocated_days'))->toBe(22.4);
});

// ── 5. The flat field is not consulted ─────────────────────────────────────

test('annual leave ignores annual_allocation_days', function () {
    elpYears();
    elpUkPolicy();
    $type = elpAnnualType();

    // A number here must have no effect whatsoever on AL.
    $type->update(['annual_allocation_days' => 12]);

    $employee = elpHire(daysPerWeek: 5);

    expect((float) LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->value('allocated_days'))->toBe(28.0);
});

// ── 6, 7. Missing data blocks rather than guesses ──────────────────────────

test('no policy blocks provisioning rather than falling back', function () {
    elpYears();
    elpAnnualType();
    LeavePolicy::query()->forceDelete();

    $employee = elpHire();

    $result = app(LeaveProvisioningService::class)->provision($employee->fresh());

    expect($result['provisioned'])->toBeFalse()
        ->and(implode(' ', $result['issues']))->toContain('No leave policy');
});

test('an unverified working pattern blocks provisioning when no default is configured', function () {
    // The approved company default fills a missing pattern. With none
    // configured, provisioning must stop rather than pick a number.
    config(['leave_provisioning.default_working_days_per_week' => null]);
    elpYears();
    elpUkPolicy();
    $type = elpAnnualType();

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'working_pattern' => 'regular',
        'working_days_per_week' => null,
        'working_days' => null,
    ]);

    expect(LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->exists())->toBeFalse();

    $result = app(LeaveProvisioningService::class)->provision($employee->fresh());

    expect($result['provisioned'])->toBeFalse()
        ->and(implode(' ', $result['issues']))->toContain('Working pattern required for verified entitlement');
});

// ── 8, 9. Nothing historical is invented ───────────────────────────────────

test('provisioning creates no carry forward', function () {
    elpYears();
    elpUkPolicy();
    $type = elpAnnualType();

    $employee = elpHire();

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->first();

    expect((float) $balance->carried_forward_days)->toBe(0.0)
        ->and(LeaveCarryForwardTransaction::where('employee_id', $employee->id)->exists())->toBeFalse();
});

test('provisioning creates no previous-year balance', function () {
    [$prev, $curr] = elpYears();
    elpUkPolicy();
    elpAnnualType();

    $employee = elpHire();

    expect(LeaveBalance::where('employee_id', $employee->id)->where('year', $prev->legacyYear())->exists())->toBeFalse()
        ->and(LeaveBalance::where('employee_id', $employee->id)->where('year', $curr->legacyYear())->exists())->toBeTrue();
});

// ── 10, 11. Existing employees are left alone ──────────────────────────────

test('an existing policy is never overwritten', function () {
    elpYears();
    elpUkPolicy();
    elpAnnualType();

    $other = LeavePolicy::create([
        'name' => 'Part Time '.Str::random(4),
        'statutory_weeks' => 5.60,
        'bank_holiday_treatment' => 'additional',
        'max_carry_over_days' => null,
        'is_default' => false,
        'is_active' => true,
    ]);

    $employee = elpHire(overrides: ['leave_policy_id' => $other->id]);

    app(LeaveProvisioningService::class)->provision($employee->fresh());

    expect($employee->fresh()->leave_policy_id)->toBe($other->id);
});

test('an existing balance is never overwritten', function () {
    [, $curr] = elpYears();
    elpUkPolicy();
    $type = elpAnnualType();

    $employee = elpHire();

    // Simulate a year in progress.
    LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
        ->update(['used_days' => 4, 'encashed_days' => 1, 'carried_forward_days' => 3]);

    app(LeaveProvisioningService::class)->provision($employee->fresh());

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $balance->used_days)->toBe(4.0)
        ->and((float) $balance->encashed_days)->toBe(1.0)
        ->and((float) $balance->carried_forward_days)->toBe(3.0)
        ->and(LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->count())->toBe(1);
});

test('provisioning twice does not duplicate the balance', function () {
    elpYears();
    elpUkPolicy();
    $type = elpAnnualType();

    $employee = elpHire();
    $service = app(LeaveProvisioningService::class);

    $service->provision($employee->fresh());
    $service->provision($employee->fresh());

    expect(LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->count())->toBe(1);
});

// ── 15. Audit ──────────────────────────────────────────────────────────────

test('provisioning is audited with its reasoning', function () {
    [, $curr] = elpYears();
    $policy = elpUkPolicy();
    elpAnnualType();

    $employee = elpHire();

    $log = AuditLog::where('action', 'leave.entitlement_provisioned')
        ->where('subject_employee_id', $employee->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->new_values['leave_policy'])->toBe($policy->name)
        ->and($log->new_values['leave_year'])->toBe($curr->label)
        ->and((float) $log->new_values['allocated_days'])->toBe(28.0)
        ->and((float) $log->new_values['carried_forward_days'])->toBe(0.0)
        ->and($log->new_values['source'])->toBe('onboarding')
        ->and($log->new_values['working_pattern'])->not->toBe('Not recorded');
});

// ── 11 (preview), 20. The import preview says what it will do ──────────────

test('the import preview reports policy, pattern and entitlement', function () {
    elpYears();
    elpUkPolicy();
    elpAnnualType();

    $service = app(EmployeeImportService::class);
    $parsed = $service->parse([[
        'employee_id' => 'CNS021',
        'first_name' => 'Yogesh',
        'last_name' => 'Sapkal',
        'email' => 'yogesh.provision@conexus-ns.com',
        'joining_date' => '2020-12-17',
        'working_days_per_week' => 5,
    ]]);

    $leave = $parsed['rows'][0]['leave'];

    expect($leave['policy'])->toBe('UK Standard')
        ->and($leave['pattern_verified'])->toBeTrue()
        ->and($leave['annual_leave'])->toBe(28.0)
        ->and($leave['carry_forward'])->toBe('None / not imported');
});

test('the preview flags a missing working pattern instead of guessing', function () {
    config(['leave_provisioning.default_working_days_per_week' => null]);
    elpYears();
    elpUkPolicy();
    elpAnnualType();

    $service = app(EmployeeImportService::class);
    $parsed = $service->parse([[
        'employee_id' => 'CNS900',
        'first_name' => 'NoPattern',
        'email' => 'nopattern@conexus-ns.com',
        'joining_date' => '2026-07-01',
    ]]);

    $leave = $parsed['rows'][0]['leave'];

    expect($leave['pattern_verified'])->toBeFalse()
        ->and($leave['annual_leave'])->toBeNull()
        ->and(implode(' ', $parsed['rows'][0]['warnings']))->toContain('Working pattern required');
});

test('the preview writes nothing', function () {
    elpYears();
    elpUkPolicy();
    elpAnnualType();

    $before = LeaveBalance::count();

    app(EmployeeImportService::class)->parse([[
        'employee_id' => 'CNS901',
        'first_name' => 'Preview',
        'email' => 'preview.only@conexus-ns.com',
        'joining_date' => '2026-07-01',
        'working_days_per_week' => 5,
    ]]);

    expect(LeaveBalance::count())->toBe($before)
        ->and(Employee::where('employee_id', 'CNS901')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'leave.entitlement_provisioned')->count())->toBe(0);
});

// ── 16. No legacy provisioning path survives ───────────────────────────────

test('no runtime path calls the legacy flat allocation', function () {
    // Structural. The old call seeded flat per-type days keyed on the calendar
    // year and assigned no policy; a single surviving caller reintroduces all
    // three faults.
    $offenders = [];

    foreach (['app', 'routes'] as $dir) {
        $directory = new RecursiveDirectoryIterator(base_path($dir));

        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // The definition itself is allowed to remain; a call is not.
            if (str_contains($contents, 'initializeFromPolicy($') || str_contains($contents, 'initializeForEmployee($')) {
                if (! str_contains($file->getPathname(), 'LeaveBalanceService.php')) {
                    $offenders[] = $file->getPathname();
                }
            }
        }
    }

    expect($offenders)->toBeEmpty();
});

test('the employee create form writes into the leave year', function () {
    // Its per-employee allocations were keyed on the calendar year.
    [, $curr] = elpYears();

    expect($curr->legacyYear())->toBe(app(LeaveYearResolver::class)->legacyYearFor());

    $source = file_get_contents(app_path('Livewire/Employees/EmployeeCreate.php'));

    // The only surviving mention is the comment explaining the fix.
    expect(substr_count($source, 'now()->year'))->toBe(1)
        ->and($source)->toContain('legacyYear()');
});
