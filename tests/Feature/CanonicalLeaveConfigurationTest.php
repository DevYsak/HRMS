<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\Leave\LeaveCarryOverService;
use App\Services\Leave\LeaveEntitlementService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveBalanceService;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The canonical leave configuration.
 *
 * Annual entitlement comes from the policy and the working pattern, never from
 * a flat number on the leave type. The carry-forward ceiling comes from the
 * policy, never from the type. And "unlimited" removes the ceiling, not the
 * approval — nothing carries without an explicit HR decision.
 */
function clcYears(): array
{
    return [
        LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
        LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
    ];
}

function clcPolicy(?float $maxCarryOver): LeavePolicy
{
    return LeavePolicy::create([
        'name' => 'Policy '.Str::random(5),
        'statutory_weeks' => 5.60,
        'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => 'additional',
        'max_carry_over_days' => $maxCarryOver,
        'irregular_accrual_rate' => 0.1207,
        'is_default' => false,
        'is_active' => true,
    ]);
}

function clcEmployee(?LeavePolicy $policy = null): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'leave_policy_id' => $policy?->id,
        'working_pattern' => 'regular',
        'working_days_per_week' => 5,
        'working_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
    ]);
}

function clcAnnualType(): LeaveType
{
    return LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'annual_allocation_days' => null,
        'allow_carry_forward' => true,
        'carry_forward_mode' => 'hr_approval',
        // Deliberately generous. The policy is what limits carry-forward now,
        // so a type-level number must have no effect at all.
        'carry_forward_limit' => 3,
    ]);
}

function clcHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

function clcPrevious(Employee $e, LeaveType $t, LeaveYear $prev, float $allocated, float $used): void
{
    LeaveBalance::create([
        'employee_id' => $e->id,
        'leave_type_id' => $t->id,
        'leave_year_id' => $prev->id,
        'year' => $prev->legacyYear(),
        'allocated_days' => $allocated,
        'used_days' => $used,
    ]);
}

// ── Annual entitlement comes from the policy, not the type ─────────────────

test('annual entitlement is calculated from the policy and working pattern', function () {
    [, $curr] = clcYears();
    $employee = clcEmployee(clcPolicy(null));

    $entitlement = app(LeaveEntitlementService::class)->for($employee, $curr);

    expect($entitlement->totalDays())->toBe(28.0)
        ->and($entitlement->patternAssumed)->toBeFalse()
        ->and($entitlement->explanation)->toContain('5.6 weeks x 5 days/week = 28 days statutory');
});

test('a verified working pattern removes the assumed flag', function () {
    [, $curr] = clcYears();

    $unverified = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'working_pattern' => 'regular',
        'working_days_per_week' => null,
        'working_days' => null,
    ]);

    $svc = app(LeaveEntitlementService::class);

    expect($svc->for($unverified, $curr)->patternAssumed)->toBeTrue();

    $unverified->update(['working_days_per_week' => 5]);

    expect($svc->for($unverified->fresh(), $curr)->patternAssumed)->toBeFalse();
});

test('bank holidays are additional and do not consume the entitlement', function () {
    [, $curr] = clcYears();
    $employee = clcEmployee(clcPolicy(null));

    $entitlement = app(LeaveEntitlementService::class)->for($employee, $curr);

    expect($entitlement->bankHolidayDays)->toBe(0.0)
        ->and($entitlement->totalDays())->toBe(28.0);
});

// ── The carry-forward ceiling comes from the policy ────────────────────────

test('a null policy limit means unlimited', function () {
    [$prev, $curr] = clcYears();
    $employee = clcEmployee(clcPolicy(null));
    $type = clcAnnualType();

    clcPrevious($employee, $type, $prev, allocated: 28, used: 8);

    $row = app(LeaveCarryOverService::class)->preview($prev, $curr)
        ->firstWhere('employee_id', $employee->id);

    // 20 eligible, uncapped — the type's limit of 3 is ignored.
    expect($row['eligible'])->toBe(20.0)
        ->and($row['carry'])->toBe(20.0)
        ->and($row['limit'])->toBeNull();
});

test('a policy limit of zero means no carry forward', function () {
    [$prev, $curr] = clcYears();
    $employee = clcEmployee(clcPolicy(0));
    $type = clcAnnualType();

    clcPrevious($employee, $type, $prev, allocated: 28, used: 8);

    // Nothing carryable, so the row does not appear at all.
    expect(app(LeaveCarryOverService::class)->preview($prev, $curr))->toBeEmpty();
});

test('a numeric policy limit caps the amount', function () {
    [$prev, $curr] = clcYears();
    $employee = clcEmployee(clcPolicy(10));
    $type = clcAnnualType();

    clcPrevious($employee, $type, $prev, allocated: 28, used: 8);

    $row = app(LeaveCarryOverService::class)->preview($prev, $curr)
        ->firstWhere('employee_id', $employee->id);

    expect($row['eligible'])->toBe(20.0)
        ->and($row['carry'])->toBe(10.0)
        ->and($row['limit'])->toBe(10.0);
});

test('the leave type limit no longer overrides the policy', function () {
    // carry_forward_limit = 3 on the type; the policy says unlimited.
    [$prev, $curr] = clcYears();
    $employee = clcEmployee(clcPolicy(null));
    $type = clcAnnualType();

    expect((float) $type->carry_forward_limit)->toBe(3.0);

    clcPrevious($employee, $type, $prev, allocated: 28, used: 8);

    $row = app(LeaveCarryOverService::class)->preview($prev, $curr)
        ->firstWhere('employee_id', $employee->id);

    expect($row['carry'])->toBe(20.0);
});

// ── Unlimited removes the ceiling, not the approval ────────────────────────

test('unlimited still requires an explicit HR decision', function () {
    [$prev, $curr] = clcYears();
    $employee = clcEmployee(clcPolicy(null));
    $type = clcAnnualType();
    $hr = clcHr();
    $this->actingAs($hr);

    clcPrevious($employee, $type, $prev, allocated: 28, used: 8);

    // Nothing has been carried merely because the policy permits it.
    // Scoped to this leave type: onboarding provisions Annual Leave for the
    // current year, which is a different row and a different question.
    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->where('year', $curr->legacyYear())
        ->first();

    expect($balance)->toBeNull();

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 6);

    expect((float) LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->where('year', $curr->legacyYear())->value('carried_forward_days'))->toBe(6.0);
});

test('unlimited never carries more than the eligible amount', function () {
    [$prev, $curr] = clcYears();
    $employee = clcEmployee(clcPolicy(null));
    $type = clcAnnualType();
    $hr = clcHr();
    $this->actingAs($hr);

    clcPrevious($employee, $type, $prev, allocated: 28, used: 8);

    expect(fn () => app(LeaveCarryForwardService::class)
        ->apply($employee, $type, $prev, $curr, $hr, days: 21))
        ->toThrow(RuntimeException::class);
});

test('unknown historical usage still awaits an HR decision under unlimited', function () {
    [$prev, $curr] = clcYears();
    $employee = clcEmployee(clcPolicy(null));
    $type = clcAnnualType();
    $hr = clcHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 10, null, null, 'Closing balance only', null, $hr
    );

    $row = app(LeaveCarryOverService::class)->preview($prev, $curr)
        ->firstWhere('employee_id', $employee->id);

    expect($row['figures_known'])->toBeFalse()
        ->and($row['eligible'])->toBeNull()
        ->and($row['closing_balance'])->toBe(10.0);

    expect(fn () => app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr))
        ->toThrow(RuntimeException::class, 'not available');
});

// ── The master data the migration establishes ──────────────────────────────

// ── What the master-data migration does to an existing database ───────────
//
// A fresh test database has none of the legacy types, so asserting the
// migration's effect requires building the "before" first. These run the
// migration itself rather than trusting the dev database to look right.

function clcRunMasterDataMigration(): void
{
    $migration = require database_path('migrations/2026_08_27_223356_establish_canonical_leave_master_data.php');
    $migration->up();
}

function clcLegacyMaster(): array
{
    $cl = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL', 'category' => 'annual', 'annual_allocation_days' => 12, 'allow_carry_forward' => true]);
    $el = LeaveType::create(['name' => 'Earned Leave', 'code' => 'EL', 'category' => 'annual', 'carry_forward_limit' => 30]);
    $cus = LeaveType::create(['name' => 'Custom Leave', 'code' => 'CUS', 'category' => 'custom']);
    $ul = LeaveType::create(['name' => 'Unauthorized Leave', 'code' => 'UL', 'category' => 'unauthorized', 'is_paid' => false, 'is_sandwich_applicable' => true]);
    $unpaid = LeaveType::create(['name' => 'Unpaid Leave', 'code' => null, 'category' => 'unpaid', 'is_paid' => false]);
    $ml = LeaveType::create(['name' => 'Maternity Leave', 'code' => 'ML', 'category' => 'maternity', 'annual_allocation_days' => 180]);
    $pl = LeaveType::create(['name' => 'Paternity Leave', 'code' => 'PL', 'category' => 'paternity', 'annual_allocation_days' => 15]);
    $wfh = LeaveType::create(['name' => 'Work From Home', 'code' => 'WFH', 'category' => 'wfh']);

    return compact('cl', 'el', 'cus', 'ul', 'unpaid', 'ml', 'pl', 'wfh');
}

function clcUkPolicy(): LeavePolicy
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

test('the migration creates Annual Leave, policy driven', function () {
    clcLegacyMaster();
    clcRunMasterDataMigration();

    $al = LeaveType::where('code', 'AL')->first();

    expect($al)->not->toBeNull()
        ->and($al->name)->toBe('Annual Leave')
        ->and($al->category)->toBe('annual')
        // The flat field must stay empty or it becomes a second answer.
        ->and($al->annual_allocation_days)->toBeNull()
        ->and($al->carry_forward_mode)->toBe('hr_approval')
        ->and((bool) $al->is_sandwich_applicable)->toBeFalse();
});

test('the migration renames UL to UNA and turns sandwich off', function () {
    clcLegacyMaster();
    clcRunMasterDataMigration();

    $una = LeaveType::where('code', 'UNA')->first();

    expect($una)->not->toBeNull()
        ->and($una->name)->toBe('Unauthorized Leave')
        ->and((bool) $una->is_sandwich_applicable)->toBeFalse()
        ->and($una->sandwich_mode)->toBe('off')
        ->and($una->payment_mode)->toBe('unpaid')
        // The code is freed for its canonical meaning.
        ->and(LeaveType::withTrashed()->where('code', 'UL')->exists())->toBeFalse();
});

test('the migration retires legacy types without deleting them', function () {
    clcLegacyMaster();
    clcRunMasterDataMigration();

    foreach (['CL', 'EL', 'CUS'] as $code) {
        expect(LeaveType::where('code', $code)->exists())->toBeFalse()
            ->and(LeaveType::withTrashed()->where('code', $code)->exists())->toBeTrue();
    }

    expect(LeaveType::withTrashed()->whereNull('code')->where('name', 'Unpaid Leave')->first()->trashed())->toBeTrue();
});

test('retiring a type leaves its balances attached', function () {
    $master = clcLegacyMaster();
    [$prev] = clcYears();
    $employee = clcEmployee();

    clcPrevious($employee, $master['cl'], $prev, allocated: 12, used: 2);

    clcRunMasterDataMigration();

    // Counting rows would be brittle: creating an employee also initialises a
    // balance for any type with an allocation. What matters is that the row
    // recorded against CL still exists, still says what it said, and still
    // points at CL.
    $previous = LeaveBalance::where('leave_type_id', $master['cl']->id)
        ->where('year', $prev->legacyYear())
        ->first();

    expect($previous)->not->toBeNull()
        ->and((float) $previous->allocated_days)->toBe(12.0)
        ->and((float) $previous->used_days)->toBe(2.0)
        ->and($previous->leave_type_id)->toBe($master['cl']->id)
        ->and(LeaveType::withTrashed()->find($master['cl']->id))->not->toBeNull();
});

test('the migration creates neither CSL nor MDL', function () {
    clcLegacyMaster();
    clcRunMasterDataMigration();

    expect(LeaveType::withTrashed()->where('code', 'CSL')->exists())->toBeFalse()
        ->and(LeaveType::withTrashed()->where('code', 'MDL')->exists())->toBeFalse();
});

test('the migration leaves Maternity and Paternity values alone', function () {
    clcLegacyMaster();
    clcRunMasterDataMigration();

    expect((float) LeaveType::where('code', 'ML')->value('annual_allocation_days'))->toBe(180.0)
        ->and((float) LeaveType::where('code', 'PL')->value('annual_allocation_days'))->toBe(15.0);
});

test('Work From Home survives and is not annual leave', function () {
    clcLegacyMaster();
    clcRunMasterDataMigration();

    $wfh = LeaveType::where('code', 'WFH')->first();

    expect($wfh)->not->toBeNull()
        ->and($wfh->category)->not->toBe('annual')
        ->and((bool) $wfh->allow_carry_forward)->toBeFalse();
});

test('the migration links active employees to the UK policy with a verified pattern', function () {
    $uk = clcUkPolicy();

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'leave_policy_id' => null,
        'working_days_per_week' => null,
    ]);

    clcRunMasterDataMigration();

    $fresh = $employee->fresh();

    expect($fresh->leave_policy_id)->toBe($uk->id)
        ->and((float) $fresh->working_days_per_week)->toBe(5.0)
        ->and($fresh->working_pattern)->toBe('regular');
});

test('the migration does not overwrite an employee who already has a pattern', function () {
    clcUkPolicy();

    $partTime = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'working_days_per_week' => 3,
    ]);

    clcRunMasterDataMigration();

    expect((float) $partTime->fresh()->working_days_per_week)->toBe(3.0);
});

test('running the master-data migration twice changes nothing', function () {
    clcLegacyMaster();

    clcRunMasterDataMigration();
    $after = LeaveType::withTrashed()->count();

    clcRunMasterDataMigration();

    expect(LeaveType::withTrashed()->count())->toBe($after)
        ->and(LeaveType::where('code', 'AL')->count())->toBe(1);
});

// ── The seeder must never rewrite live configuration ───────────────────────

test('the seeder is idempotent', function () {
    (new LeaveTypeSeeder)->run();
    $after = LeaveType::withTrashed()->count();

    (new LeaveTypeSeeder)->run();
    (new LeaveTypeSeeder)->run();

    expect(LeaveType::withTrashed()->count())->toBe($after);
});

test('the seeder does not disable carry forward on an existing type', function () {
    // The exact damage the old updateOrCreate would have done to live CL.
    $type = LeaveType::create([
        'name' => 'Casual Leave',
        'code' => 'CL',
        'category' => 'annual',
        'allow_carry_forward' => true,
        'carry_forward_limit' => 12,
    ]);

    (new LeaveTypeSeeder)->run();

    expect((bool) $type->fresh()->allow_carry_forward)->toBeTrue()
        ->and((float) $type->fresh()->carry_forward_limit)->toBe(12.0);
});

test('the seeder does not resurrect a retired type', function () {
    clcLegacyMaster();
    clcRunMasterDataMigration();

    $retired = LeaveType::withTrashed()->where('code', 'CUS')->first();
    expect($retired->trashed())->toBeTrue();

    (new LeaveTypeSeeder)->run();

    expect($retired->fresh()->trashed())->toBeTrue();
});

// ── The leave year is unchanged by any of this ─────────────────────────────

test('the leave year still runs July to June', function () {
    $resolver = app(LeaveYearResolver::class);

    expect($resolver->forDate(Carbon::parse('2026-06-30'))->label)->toBe('2025/26')
        ->and($resolver->forDate(Carbon::parse('2026-07-01'))->label)->toBe('2026/27');
});
