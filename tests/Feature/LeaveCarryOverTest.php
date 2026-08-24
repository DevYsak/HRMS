<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveCarryOverService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Carrying unused leave into the next leave year.
 *
 * The previous implementation wrote the carried amount into allocated_days,
 * wiping the new year's fresh entitlement; reset used_days to zero, erasing
 * bookings on any re-run; ignored encashed_days, carrying days that had
 * already been paid out; and worked in calendar years, which cannot express
 * 1 July to 30 June.
 */
function lcoSetup(): array
{
    Company::query()->delete();
    Company::create([
        'name' => 'Conexus Technologies', 'country' => 'India',
        'holiday_calendar' => 'UK', 'leave_year_start_month' => 7, 'leave_year_start_day' => 1,
    ]);

    $resolver = app(LeaveYearResolver::class);

    return [
        $resolver->forDate(Carbon::parse('2025-09-01')),  // 2025/26
        $resolver->forDate(Carbon::parse('2026-09-01')),  // 2026/27
    ];
}

function lcoEmployee(): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    // The observer seeds standard balances; clear them so each test controls
    // exactly what exists.
    LeaveBalance::where('employee_id', $employee->id)->delete();

    return $employee;
}

function lcoType(array $attributes = []): LeaveType
{
    return LeaveType::create($attributes + [
        'name' => 'Annual '.Str::random(5),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_paid_request' => true,
        'allow_carry_forward' => true,
    ]);
}

function lcoBalance(Employee $e, LeaveType $t, $year, array $values): LeaveBalance
{
    return LeaveBalance::create($values + [
        'employee_id' => $e->id,
        'leave_type_id' => $t->id,
        'year' => $year->legacyYear(),
        'leave_year_id' => $year->id,
    ]);
}

// ── The formula ────────────────────────────────────────────────────────────

test('eligible days subtract both used and encashed', function () {
    // The old code computed allocated - used only, so encashed days were
    // carried forward as well and the employee got them twice.
    [$from] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType();

    $balance = lcoBalance($employee, $type, $from, [
        'allocated_days' => 20, 'used_days' => 5, 'encashed_days' => 3,
    ]);

    expect(app(LeaveCarryOverService::class)->eligibleDays($balance))->toBe(12.0);
});

test('the carry is capped by the leave type limit', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType(['carry_forward_limit' => 5]);

    lcoBalance($employee, $type, $from, ['allocated_days' => 20, 'used_days' => 0, 'encashed_days' => 0]);

    $preview = app(LeaveCarryOverService::class)->preview($from, $to);

    expect($preview)->toHaveCount(1)
        ->and($preview->first()['eligible'])->toBe(20.0)
        ->and($preview->first()['carry'])->toBe(5.0);
});

test('a type that does not allow carry-forward is skipped', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType(['allow_carry_forward' => false]);

    lcoBalance($employee, $type, $from, ['allocated_days' => 20, 'used_days' => 0]);

    expect(app(LeaveCarryOverService::class)->preview($from, $to))->toHaveCount(0);
});

test('a fully used balance carries nothing', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType();

    lcoBalance($employee, $type, $from, ['allocated_days' => 12, 'used_days' => 12]);

    expect(app(LeaveCarryOverService::class)->preview($from, $to))->toHaveCount(0);
});

// ── Fresh entitlement must survive ─────────────────────────────────────────

test('carrying over adds to the new year rather than replacing it', function () {
    // The headline defect: an employee carrying 2 days used to end up with 2
    // days for the whole year instead of their 28 plus 2.
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType();

    lcoBalance($employee, $type, $from, ['allocated_days' => 12, 'used_days' => 10]);
    lcoBalance($employee, $type, $to, ['allocated_days' => 28, 'used_days' => 0]);

    app(LeaveCarryOverService::class)->execute($from, $to);

    $target = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $to->legacyYear())->first();

    expect((float) $target->allocated_days)->toBe(30.0)
        ->and((float) $target->carried_forward_days)->toBe(2.0);
});

test('carrying over never resets days already booked in the new year', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType();

    lcoBalance($employee, $type, $from, ['allocated_days' => 12, 'used_days' => 10]);
    lcoBalance($employee, $type, $to, ['allocated_days' => 28, 'used_days' => 6]);

    app(LeaveCarryOverService::class)->execute($from, $to);

    $target = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $to->legacyYear())->first();

    expect((float) $target->used_days)->toBe(6.0)
        ->and((float) $target->allocated_days)->toBe(30.0);
});

// ── Idempotency ────────────────────────────────────────────────────────────

test('running carry-over twice does not double the carried days', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType();

    lcoBalance($employee, $type, $from, ['allocated_days' => 12, 'used_days' => 8]);
    lcoBalance($employee, $type, $to, ['allocated_days' => 28, 'used_days' => 0]);

    $service = app(LeaveCarryOverService::class);
    $service->execute($from, $to);
    $service->execute($from, $to);
    $service->execute($from, $to);

    $target = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $to->legacyYear())->first();

    expect((float) $target->allocated_days)->toBe(32.0)
        ->and((float) $target->carried_forward_days)->toBe(4.0);
});

test('a re-run after more leave is used recalculates rather than stacking', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType();

    $source = lcoBalance($employee, $type, $from, ['allocated_days' => 12, 'used_days' => 8]);
    lcoBalance($employee, $type, $to, ['allocated_days' => 28, 'used_days' => 0]);

    $service = app(LeaveCarryOverService::class);
    $service->execute($from, $to);

    // A late booking lands against the source year.
    $source->update(['used_days' => 10]);
    $service->execute($from, $to);

    $target = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $to->legacyYear())->first();

    expect((float) $target->carried_forward_days)->toBe(2.0)
        ->and((float) $target->allocated_days)->toBe(30.0);
});

// ── Leave year, not calendar year ──────────────────────────────────────────

test('carry-over runs between leave years spanning July to June', function () {
    [$from, $to] = lcoSetup();

    expect($from->label)->toBe('2025/26')
        ->and($from->starts_on->toDateString())->toBe('2025-07-01')
        ->and($to->label)->toBe('2026/27')
        ->and($to->starts_on->toDateString())->toBe('2026-07-01');
});

test('the new balance is linked to the target leave year', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType();

    lcoBalance($employee, $type, $from, ['allocated_days' => 12, 'used_days' => 6]);

    app(LeaveCarryOverService::class)->execute($from, $to);

    $target = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $to->legacyYear())->first();

    expect($target->leave_year_id)->toBe($to->id);
});

// ── Preview ────────────────────────────────────────────────────────────────

test('the preview reports its working and changes nothing', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType(['carry_forward_limit' => 5]);

    lcoBalance($employee, $type, $from, ['allocated_days' => 20, 'used_days' => 4, 'encashed_days' => 2]);

    $before = LeaveBalance::count();
    $row = app(LeaveCarryOverService::class)->preview($from, $to)->first();

    expect($row['allocated'])->toBe(20.0)
        ->and($row['used'])->toBe(4.0)
        ->and($row['encashed'])->toBe(2.0)
        ->and($row['eligible'])->toBe(14.0)
        ->and($row['limit'])->toBe(5.0)
        ->and($row['carry'])->toBe(5.0)
        // Nothing written.
        ->and(LeaveBalance::count())->toBe($before);
});

test('execute reports what it did', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType();

    lcoBalance($employee, $type, $from, ['allocated_days' => 12, 'used_days' => 9]);

    $result = app(LeaveCarryOverService::class)->execute($from, $to);

    expect($result['employees'])->toBe(1)
        ->and($result['rows'])->toBe(1)
        ->and($result['days'])->toBe(3.0);
});

test('an inactive employee is not carried over', function () {
    [$from, $to] = lcoSetup();
    $employee = lcoEmployee();
    $type = lcoType();

    lcoBalance($employee, $type, $from, ['allocated_days' => 12, 'used_days' => 0]);
    $employee->update(['status' => 'inactive']);

    expect(app(LeaveCarryOverService::class)->preview($from, $to))->toHaveCount(0);
});
