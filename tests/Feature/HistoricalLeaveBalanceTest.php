<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\TimeOff\LeaveCarryForward;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Entering an employee's position in a past leave year by hand.
 *
 * Carry forward cannot be exercised at all without a previous year to carry
 * from, and a credit/debit adjustment cannot state one: "28 allocated, 10 used"
 * is two facts, and crediting 18 to express it loses both — along with any way
 * to check the arithmetic later.
 *
 * The year written to is the one selected on the page. Writing to the current
 * calendar year instead would silently credit days to a year the employee has
 * not reached.
 */
function hlbYears(): array
{
    return [
        LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
        LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
    ];
}

function hlbEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function hlbType(float $limit = 0): LeaveType
{
    return LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'is_system_controlled' => false,
        'allow_carry_forward' => true,
        'carry_forward_limit' => $limit,
    ]);
}

function hlbHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

// ── 1 & 2. The right year, and only the right year ─────────────────────────

test('a historical balance lands in the selected leave year', function () {
    [$prev] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();
    $hr = hlbHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 10, 0, 'Historical 2025/26 opening entitlement', null, $hr
    );

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('year', 2025)->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->allocated_days)->toBe(28.0)
        ->and((float) $balance->used_days)->toBe(10.0)
        ->and((float) $balance->encashed_days)->toBe(0.0)
        // Linked by identity, not only by the legacy integer.
        ->and($balance->leave_year_id)->toBe($prev->id);
});

test('recording 2025/26 does not write to 2026/27', function () {
    [$prev, $curr] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();
    $hr = hlbHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 10, 0, 'Historical opening entitlement', null, $hr
    );

    expect(LeaveBalance::where('employee_id', $employee->id)->where('year', $curr->legacyYear())->exists())->toBeFalse()
        ->and(LeaveBalance::where('employee_id', $employee->id)->count())->toBe(1);
});

test('the modal writes to the year selected on the page', function () {
    [$prev, $curr] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();

    Livewire::actingAs(hlbHr())
        ->test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Leave')
        ->set('leaveBalanceYear', (string) $prev->legacyYear())
        ->call('openManageLeaveModal')
        ->set('leaveAdjustMode', 'historical')
        ->set('leaveAdjustTypeId', $type->id)
        ->set('historicalAllocated', 28)
        ->set('historicalUsed', 10)
        ->set('historicalEncashed', 0)
        ->set('leaveAdjustReason', 'Historical 2025/26 opening entitlement')
        ->call('submitHistoricalBalance')
        ->assertHasNoErrors();

    $balance = LeaveBalance::where('employee_id', $employee->id)->first();

    expect((int) $balance->year)->toBe($prev->legacyYear())
        ->and($balance->leave_year_id)->toBe($prev->id)
        ->and((float) $balance->allocated_days)->toBe(28.0)
        ->and((float) $balance->used_days)->toBe(10.0);
});

test('a credit adjustment also links the selected leave year', function () {
    [$prev] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();
    $hr = hlbHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->adjust(
        $employee, $type, 'credit', 5, 'Correction', '', $hr, $prev->legacyYear()
    );

    $balance = LeaveBalance::where('employee_id', $employee->id)->first();

    expect((int) $balance->year)->toBe($prev->legacyYear())
        ->and($balance->leave_year_id)->toBe($prev->id);
});

// ── 3 & 4. It reaches carry forward, with the right arithmetic ─────────────

test('the historical balance appears in the carry forward preview', function () {
    [$prev, $curr] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();
    $hr = hlbHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 10, 0, 'Historical opening entitlement', null, $hr
    );

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSet('hasEligibleRows', true)
        ->assertViewHas('rows', function ($rows) use ($employee, $type) {
            $row = $rows->firstWhere('employee_id', $employee->id);

            return $row
                && $row['leave_type_id'] === $type->id
                && $row['allocated'] === 28.0
                && $row['used'] === 10.0
                && $row['encashed'] === 0.0
                && $row['eligible'] === 18.0;
        });
});

test('eligible is allocated minus used minus encashed', function () {
    [$prev, $curr] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();
    $hr = hlbHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 10, 3, 'Historical opening entitlement', null, $hr
    );

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)
        ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('employee_id', $employee->id)['eligible'] === 15.0);
});

test('the policy limit still caps what carries', function () {
    // The historical entry states the year; the policy decides how much of it
    // may travel.
    [$prev] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType(limit: 10);
    $hr = hlbHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 10, 0, 'Historical opening entitlement', null, $hr
    );

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)
        ->assertViewHas('rows', function ($rows) use ($employee) {
            $row = $rows->firstWhere('employee_id', $employee->id);

            return $row['eligible'] === 18.0 && $row['carry'] === 10.0;
        });
});

// ── 5 & 6. Applying, and applying again ────────────────────────────────────

test('applying carry forward moves 18 days into the next year', function () {
    [$prev, $curr] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();
    $hr = hlbHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 10, 0, 'Historical opening entitlement', null, $hr
    );

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)->call('applyAll');

    $target = LeaveBalance::where('employee_id', $employee->id)->where('year', $curr->legacyYear())->first();

    expect($target)->not->toBeNull()
        ->and((float) $target->carried_forward_days)->toBe(18.0)
        // The previous year is left exactly as recorded.
        ->and((float) LeaveBalance::where('employee_id', $employee->id)->where('year', $prev->legacyYear())->value('used_days'))->toBe(10.0);
});

test('applying twice does not duplicate the carried days', function () {
    [$prev, $curr] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();
    $hr = hlbHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 10, 0, 'Historical opening entitlement', null, $hr
    );

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)->call('applyAll');
    Livewire::actingAs($hr)->test(LeaveCarryForward::class)->call('applyAll');

    $target = LeaveBalance::where('employee_id', $employee->id)->where('year', $curr->legacyYear())->first();

    expect((float) $target->carried_forward_days)->toBe(18.0)
        ->and(LeaveCarryForwardTransaction::count())->toBe(1);
});

// ── 7. Audit ───────────────────────────────────────────────────────────────

test('a historical entry records all three figures on both sides', function () {
    [$prev] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();
    $hr = hlbHr();
    $this->actingAs($hr);

    $service = app(LeaveBalanceService::class);
    $service->setHistoricalBalance($employee, $type, $prev, 20, 5, 0, 'First entry', null, $hr);
    $service->setHistoricalBalance($employee, $type, $prev, 28, 10, 2, 'Corrected from HR sheet', 'Signed off by director', $hr);

    $log = AuditLog::where('action', 'leave.historical_balance_set')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and((float) $log->old_values['allocated_days'])->toBe(20.0)
        ->and((float) $log->old_values['used_days'])->toBe(5.0)
        ->and((float) $log->old_values['encashed_days'])->toBe(0.0)
        ->and((float) $log->new_values['allocated_days'])->toBe(28.0)
        ->and((float) $log->new_values['used_days'])->toBe(10.0)
        ->and((float) $log->new_values['encashed_days'])->toBe(2.0)
        ->and($log->new_values['leave_year_label'])->toBe('2025/26')
        ->and($log->new_values['leave_year_id'])->toBe($prev->id)
        ->and((float) $log->new_values['eligible_for_carry_forward'])->toBe(16.0)
        ->and($log->new_values['performed_by'])->toBe($hr->id)
        ->and($log->new_values['remarks'])->toBe('Signed off by director')
        ->and($log->reason)->toBe('Corrected from HR sheet')
        ->and($log->subject_employee_id)->toBe($employee->id)
        ->and($log->created_at)->not->toBeNull();
});

test('correcting a historical entry keeps the earlier one', function () {
    [$prev] = hlbYears();
    $employee = hlbEmployee();
    $type = hlbType();
    $hr = hlbHr();
    $this->actingAs($hr);

    $service = app(LeaveBalanceService::class);
    $service->setHistoricalBalance($employee, $type, $prev, 20, 5, 0, 'First entry', null, $hr);
    $service->setHistoricalBalance($employee, $type, $prev, 28, 10, 0, 'Corrected', null, $hr);

    expect(AuditLog::where('action', 'leave.historical_balance_set')->count())->toBe(2);
});

// ── Guards ─────────────────────────────────────────────────────────────────

test('used plus encashed cannot exceed allocated', function () {
    // Carry forward would clamp the negative to zero and silently discard the
    // entitlement instead of reporting the contradiction.
    [$prev] = hlbYears();
    $hr = hlbHr();
    $this->actingAs($hr);

    expect(fn () => app(LeaveBalanceService::class)->setHistoricalBalance(
        hlbEmployee(), hlbType(), $prev, 10, 8, 5, 'Impossible', null, $hr
    ))->toThrow(DomainException::class);
});

test('a historical entry needs a reason', function () {
    [$prev] = hlbYears();
    $hr = hlbHr();
    $this->actingAs($hr);

    expect(fn () => app(LeaveBalanceService::class)->setHistoricalBalance(
        hlbEmployee(), hlbType(), $prev, 28, 10, 0, '   ', null, $hr
    ))->toThrow(DomainException::class);
});

test('negative figures are refused', function () {
    [$prev] = hlbYears();
    $hr = hlbHr();
    $this->actingAs($hr);

    expect(fn () => app(LeaveBalanceService::class)->setHistoricalBalance(
        hlbEmployee(), hlbType(), $prev, 28, -1, 0, 'Negative', null, $hr
    ))->toThrow(DomainException::class);
});

test('an employee cannot record a historical balance', function () {
    $employee = hlbEmployee();

    Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test(EmployeeEdit::class, ['employee' => $employee])
        ->assertForbidden();
});
