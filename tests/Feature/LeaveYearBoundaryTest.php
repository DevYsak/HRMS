<?php

use App\Enums\UserRole;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\Leave\LeaveRegularisationService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveBalanceService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The leave year runs 1 July to 30 June, and every balance must be found by
 * date rather than by clock.
 *
 * Balances are keyed on a legacy calendar-year integer. Code that wanted "this
 * year's balance" reached for now()->year, which is the calendar year — and
 * with a July start the two disagree for six months of every year. Leave taken
 * on 20 June 2025 belongs to leave year 2024/25 (integer 2024); now()->year
 * said 2025, and the days came off the wrong year.
 *
 * These are the boundary cases that catch a regression to that behaviour.
 */
function lybType(): LeaveType
{
    return LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_paid_request' => true,
        'allow_carry_forward' => true,
    ]);
}

function lybEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function lybBalance(Employee $e, LeaveType $t, int $legacyYear, float $allocated, float $used = 0): LeaveBalance
{
    return LeaveBalance::create([
        'employee_id' => $e->id,
        'leave_type_id' => $t->id,
        'year' => $legacyYear,
        'allocated_days' => $allocated,
        'used_days' => $used,
    ]);
}

function lybResolver(): LeaveYearResolver
{
    return app(LeaveYearResolver::class);
}

/**
 * Approve through the real review path, as HR — the same route the UI takes.
 */
function lybApprove(LeaveRequest $request, LeaveType $type): void
{
    app(LeaveService::class)->reviewRequest(
        $request,
        [
            'leave_type_id' => $type->id,
            'start_date' => $request->start_date->toDateString(),
            'end_date' => $request->end_date->toDateString(),
            'reason' => $request->reason,
        ],
        'approved',
        User::factory()->create(['role' => UserRole::HrAdmin])->id,
    );
}

// ── 1. The boundary itself ─────────────────────────────────────────────────

test('the four boundary dates resolve to the right leave year', function (string $date, string $label, int $legacy) {
    $year = lybResolver()->forDate(Carbon::parse($date));

    expect($year->label)->toBe($label)
        ->and($year->legacyYear())->toBe($legacy);
})->with([
    ['2025-06-20', '2024/25', 2024],
    ['2025-06-30', '2024/25', 2024],
    ['2025-07-01', '2025/26', 2025],
    ['2025-07-15', '2025/26', 2025],
]);

test('30 June and 1 July are one day apart and a year apart', function () {
    $june = lybResolver()->forDate(Carbon::parse('2025-06-30'));
    $july = lybResolver()->forDate(Carbon::parse('2025-07-01'));

    expect($june->id)->not->toBe($july->id)
        ->and($june->ends_on->toDateString())->toBe('2025-06-30')
        ->and($july->starts_on->toDateString())->toBe('2025-07-01');
});

test('legacyYearFor answers the same question as the leave year', function () {
    expect(lybResolver()->legacyYearFor(Carbon::parse('2025-06-20')))->toBe(2024)
        ->and(lybResolver()->legacyYearFor(Carbon::parse('2025-07-15')))->toBe(2025)
        // A January date is in the leave year that began the previous July.
        ->and(lybResolver()->legacyYearFor(Carbon::parse('2026-01-10')))->toBe(2025);
});

// ── 2. Historical leave deduction ──────────────────────────────────────────

test('June 2025 leave is deducted from 2024/25, not 2025', function () {
    $employee = lybEmployee();
    $type = lybType();

    $prev = lybBalance($employee, $type, 2024, allocated: 28);
    $curr = lybBalance($employee, $type, 2025, allocated: 28);

    $request = LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2025-06-20',
        'end_date' => '2025-06-20',
        'days' => 1,
        'reason' => 'Historical',
        'status' => 'pending',
    ]);

    lybApprove($request, $type);

    expect((float) $prev->fresh()->used_days)->toBe(1.0)
        ->and((float) $curr->fresh()->used_days)->toBe(0.0);
});

test('July 2025 leave is deducted from 2025/26', function () {
    $employee = lybEmployee();
    $type = lybType();

    $prev = lybBalance($employee, $type, 2024, allocated: 28);
    $curr = lybBalance($employee, $type, 2025, allocated: 28);

    $request = LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2025-07-15',
        'end_date' => '2025-07-15',
        'days' => 1,
        'reason' => 'Historical',
        'status' => 'pending',
    ]);

    lybApprove($request, $type);

    expect((float) $curr->fresh()->used_days)->toBe(1.0)
        ->and((float) $prev->fresh()->used_days)->toBe(0.0);
});

test('a balance lookup by date finds the right year', function () {
    $employee = lybEmployee();
    $type = lybType();
    lybBalance($employee, $type, 2024, allocated: 28, used: 5);
    lybBalance($employee, $type, 2025, allocated: 28, used: 2);

    $service = app(LeaveService::class);

    expect((float) $service->balanceForDate($employee->id, $type->id, Carbon::parse('2025-06-20'))->used_days)->toBe(5.0)
        ->and((float) $service->balanceForDate($employee->id, $type->id, Carbon::parse('2025-07-15'))->used_days)->toBe(2.0);
});

// ── 3. Historical regularisation ───────────────────────────────────────────

test('a June 2025 regularisation affects the 2024/25 balance', function () {
    config(['leave_regularisation.window_days' => 0]); // no window, this is historical
    $employee = lybEmployee();
    $type = lybType();

    $prev = lybBalance($employee, $type, 2024, allocated: 28);
    $curr = lybBalance($employee, $type, 2025, allocated: 28);

    $reg = app(LeaveRegularisationService::class)->submit(
        employee: $employee,
        type: $type,
        from: Carbon::parse('2025-06-20'),
        to: Carbon::parse('2025-06-20'),
        reason: 'Absent, never booked',
        requestedBy: User::factory()->create(['role' => UserRole::HrAdmin]),
    );

    app(AttendanceService::class)->approveRegularisation($reg, User::factory()->create(['role' => UserRole::SuperAdmin])->id);

    expect((float) $prev->fresh()->used_days)->toBe(1.0)
        ->and((float) $curr->fresh()->used_days)->toBe(0.0);
});

test('a July 2025 regularisation affects the 2025/26 balance', function () {
    config(['leave_regularisation.window_days' => 0]);
    $employee = lybEmployee();
    $type = lybType();

    $prev = lybBalance($employee, $type, 2024, allocated: 28);
    $curr = lybBalance($employee, $type, 2025, allocated: 28);

    $reg = app(LeaveRegularisationService::class)->submit(
        employee: $employee,
        type: $type,
        from: Carbon::parse('2025-07-15'),
        to: Carbon::parse('2025-07-15'),
        reason: 'Absent, never booked',
        requestedBy: User::factory()->create(['role' => UserRole::HrAdmin]),
    );

    app(AttendanceService::class)->approveRegularisation($reg, User::factory()->create(['role' => UserRole::SuperAdmin])->id);

    expect((float) $curr->fresh()->used_days)->toBe(1.0)
        ->and((float) $prev->fresh()->used_days)->toBe(0.0);
});

test('the regularisation balance is chosen by date, never by latest row', function () {
    // orderByDesc('year') would have picked 2025 for a June 2025 correction.
    config(['leave_regularisation.window_days' => 0]);
    $employee = lybEmployee();
    $type = lybType();

    lybBalance($employee, $type, 2024, allocated: 28);
    $newest = lybBalance($employee, $type, 2026, allocated: 28);

    $reg = app(LeaveRegularisationService::class)->submit(
        employee: $employee,
        type: $type,
        from: Carbon::parse('2025-06-20'),
        to: Carbon::parse('2025-06-20'),
        reason: 'Historical',
        requestedBy: User::factory()->create(['role' => UserRole::HrAdmin]),
    );

    app(AttendanceService::class)->approveRegularisation($reg, User::factory()->create(['role' => UserRole::SuperAdmin])->id);

    expect((float) $newest->fresh()->used_days)->toBe(0.0)
        ->and((float) LeaveBalance::where('employee_id', $employee->id)->where('year', 2024)->value('used_days'))->toBe(1.0);
});

// ── 4. Current-year behaviour is unchanged ─────────────────────────────────

test('an ordinary current leave still lands in the current leave year', function () {
    $employee = lybEmployee();
    $type = lybType();

    $currentYear = lybResolver()->legacyYearFor();
    $balance = lybBalance($employee, $type, $currentYear, allocated: 28);

    $today = now()->startOfDay();

    $request = LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => $today->toDateString(),
        'end_date' => $today->toDateString(),
        'days' => 1,
        'reason' => 'Today',
        'status' => 'pending',
    ]);

    lybApprove($request, $type);

    expect((float) $balance->fresh()->used_days)->toBe(1.0);
});

test('a manual HR adjustment lands in the current leave year', function () {
    $employee = lybEmployee();
    $type = lybType();
    $currentYear = lybResolver()->legacyYearFor();
    $balance = lybBalance($employee, $type, $currentYear, allocated: 28);

    app(LeaveBalanceService::class)->adjust(
        $employee,
        $type,
        'credit',
        2,
        'Correction',
        'Agreed with director',
        User::factory()->create(['role' => UserRole::HrAdmin]),
    );

    expect((float) $balance->fresh()->allocated_days)->toBe(30.0)
        // One row for this type, not a second one in a neighbouring year.
        ->and(LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)->count())->toBe(1);
});

// ── 5. Carry forward reads the right previous year ─────────────────────────

test('carry forward reads 2024/25 as the year before 2025/26', function () {
    $prevYear = LeaveYear::firstOrCreate(['label' => '2024/25'], ['starts_on' => '2024-07-01', 'ends_on' => '2025-06-30']);
    $currYear = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);

    expect(lybResolver()->previous($currYear)->id)->toBe($prevYear->id)
        ->and(lybResolver()->next($prevYear)->id)->toBe($currYear->id);
});

test('historical June leave reduces what carries into the next year', function () {
    // The whole point of resolving by date: leave booked in June 2025 must
    // reduce the 2024/25 eligible balance, not the following year's.
    $prevYear = LeaveYear::firstOrCreate(['label' => '2024/25'], ['starts_on' => '2024-07-01', 'ends_on' => '2025-06-30']);
    $currYear = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);

    $employee = lybEmployee();
    $type = lybType();
    lybBalance($employee, $type, 2024, allocated: 28, used: 20);

    $before = app(LeaveCarryForwardService::class)->preview($prevYear, $currYear)
        ->firstWhere('employee_id', $employee->id);

    // One more historical June day used → one less eligible to carry.
    $request = LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2025-06-20',
        'end_date' => '2025-06-20',
        'days' => 1,
        'reason' => 'Historical',
        'status' => 'pending',
    ]);
    lybApprove($request, $type);

    $after = app(LeaveCarryForwardService::class)->preview($prevYear, $currYear)
        ->firstWhere('employee_id', $employee->id);

    expect($before['eligible'])->toBe(8.0)
        ->and($after['eligible'])->toBe(7.0);
});

// ── 6. Nothing writes a calendar year any more ─────────────────────────────

test('June leave cannot quietly draw on the next leave year balance', function () {
    // The sharpest form of the bug: a balance exists for 2025/26 but not for
    // 2024/25, and a June 2025 leave is booked. Reading the calendar year would
    // have found the 2025 row and approved it, silently spending days the
    // employee had not yet earned. Resolving by date finds nothing and refuses.
    $employee = lybEmployee();
    $type = lybType();

    $wrongYear = lybBalance($employee, $type, 2025, allocated: 28);

    $request = LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2025-06-20',
        'end_date' => '2025-06-20',
        'days' => 1,
        'reason' => 'Historical',
        'status' => 'pending',
    ]);

    expect(fn () => lybApprove($request, $type))->toThrow(DomainException::class);

    // Untouched: the wrong year was never a candidate.
    expect((float) $wrongYear->fresh()->used_days)->toBe(0.0);
});

test('a leave regularisation for a June date records the correct category and year', function () {
    config(['leave_regularisation.window_days' => 0]);
    $employee = lybEmployee();
    $type = lybType();
    lybBalance($employee, $type, 2024, allocated: 28);

    $reg = app(LeaveRegularisationService::class)->submit(
        employee: $employee,
        type: $type,
        from: Carbon::parse('2025-06-20'),
        to: Carbon::parse('2025-06-20'),
        reason: 'Historical',
        requestedBy: User::factory()->create(['role' => UserRole::HrAdmin]),
    );

    expect($reg->fresh()->category)->toBe('leave')
        ->and($reg->from_date->toDateString())->toBe('2025-06-20')
        ->and(AttendanceRegularisation::where('category', 'leave')->count())->toBe(1);
});
