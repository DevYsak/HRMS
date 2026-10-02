<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\EmployeeLeaveDetail;
use App\Livewire\TimeOff\LeaveManagement;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveRolloverService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * HR enters an employee's carry forward directly from Leave Management /
 * the employee's leave detail — recorded as a carry-forward transaction
 * (from year, to year, eligible, carried, reason, who), posted as its own
 * Carry Forward bucket, audited, never a manual balance adjustment.
 *
 * Clock: 15 August 2026, inside leave year 2026/27.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-15 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'], ['label' => '2025/26']);
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    $this->seed(RolesAndPermissionsSeeder::class);
});

function cfaYear(string $label): LeaveYear
{
    return LeaveYear::where('label', $label)->firstOrFail();
}

function cfaHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => Role::where('slug', 'hr_admin')->firstOrFail()->id]);
}

/** A 20-day type whose carry forward is an HR decision, capped at 5 by the policy. */
function cfaSetup(bool $withClosingYear = true): array
{
    $policy = LeavePolicy::create([
        'name' => 'CF '.random_int(100, 999), 'statutory_weeks' => 5.6, 'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => 'additional', 'irregular_accrual_rate' => 0.1207, 'is_default' => false, 'is_active' => true,
        'max_carry_over_days' => 5,
    ]);
    $type = LeaveType::create([
        'name' => 'Privilege Leave', 'code' => 'P'.random_int(10000, 99999), 'category' => 'other',
        'is_paid' => true, 'allow_paid_request' => true, 'allow_carry_forward' => true, 'carry_forward_mode' => 'hr_approval',
    ]);
    LeavePolicyRule::create(['leave_policy_id' => $policy->id, 'leave_type_id' => $type->id, 'entitlement_method' => 'fixed_days', 'fixed_days' => 20]);

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'leave_policy_id' => $policy->id, 'joining_date' => '2023-03-01',
    ]);

    if ($withClosingYear) {
        // 2025/26: base 20, 13 used → closing 7.
        app(EnsureEmployeeLeaveBalancesService::class)->ensureType($employee, $type, cfaYear('2025/26'));
        $old = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2025)->firstOrFail();
        app(LeaveLedgerService::class)->debit($old, LeaveLedgerEntry::TYPE_USAGE, 13, Carbon::parse('2026-03-02'), 'test:used:'.$old->id);
        app(LeaveLedgerService::class)->rebuild($old);
    }

    return [$employee, $type];
}

function cfaNew(Employee $employee, LeaveType $type): LeaveBalance
{
    return LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2026)->firstOrFail();
}

function cfaDetail(User $hr, Employee $employee, LeaveType $type)
{
    return Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'carry_forward', $type->id);
}

test('HR sees from/to year and the eligible balance, then carries forward as its own bucket', function () {
    [$employee, $type] = cfaSetup();
    $hr = cfaHr();

    $component = cfaDetail($hr, $employee, $type)
        ->assertSet('cfFromYearId', cfaYear('2025/26')->id)
        ->assertSet('cfToYearId', cfaYear('2026/27')->id)
        ->assertSee('Closing balance')->assertSee('Max allowed');

    expect($component->get('carryInfo')['closing_balance'])->toBe(7.0)
        ->and($component->get('carryInfo')['eligible'])->toBe(5.0)
        ->and($component->get('carryInfo')['max_allowed'])->toBe(5.0);

    $component->set('carryDays', '5')->set('reason', 'Approved by line manager')->call('submitAction')->assertHasNoErrors();

    $new = cfaNew($employee, $type);
    $tx = LeaveCarryForwardTransaction::where('employee_id', $employee->id)->firstOrFail();

    expect((float) $new->carried_forward_days)->toBe(5.0)
        ->and((float) $new->base_days)->toBe(20.0)
        ->and((float) $new->allocated_days)->toBe(25.0)
        ->and($tx->previous_leave_year_id)->toBe(cfaYear('2025/26')->id)
        ->and($tx->current_leave_year_id)->toBe(cfaYear('2026/27')->id)
        ->and((float) $tx->eligible_days)->toBe(5.0)
        ->and((float) $tx->applied_days)->toBe(5.0)
        ->and($tx->reason)->toBe('Approved by line manager')
        ->and($tx->applied_by)->toBe($hr->id)
        // Not a manual balance adjustment.
        ->and(LeaveBalanceAdjustment::where('employee_id', $employee->id)->count())->toBe(0)
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_year_id', cfaYear('2026/27')->id)->where('entry_type', 'carry_forward')->sum('days'))->toEqual(5)
        ->and(AuditLog::where('action', 'leave.carry_forward_applied')->where('subject_employee_id', $employee->id)->where('user_id', $hr->id)->exists())->toBeTrue();
});

test('more than the maximum allowed is refused and nothing is written', function () {
    [$employee, $type] = cfaSetup();

    cfaDetail(cfaHr(), $employee, $type)
        ->set('carryDays', '6')->set('reason', 'Too generous')->call('submitAction')
        ->assertHasErrors('form');

    expect(LeaveCarryForwardTransaction::count())->toBe(0);
});

test('a reason is required', function () {
    [$employee, $type] = cfaSetup();

    cfaDetail(cfaHr(), $employee, $type)
        ->set('carryDays', '3')->set('reason', '')->call('submitAction')
        ->assertHasErrors('reason');

    expect(LeaveCarryForwardTransaction::count())->toBe(0);
});

test('with no previous-year record in the system HR states the figure, recorded as stated', function () {
    [$employee, $type] = cfaSetup(withClosingYear: false);

    $component = cfaDetail(cfaHr(), $employee, $type);
    expect($component->get('carryInfo')['source_found'])->toBeFalse()
        ->and($component->get('carryInfo')['max_allowed'])->toBe(5.0);

    $component->set('carryDays', '3')->set('reason', 'Closing balance from the old system')->call('submitAction')->assertHasNoErrors();

    $tx = LeaveCarryForwardTransaction::where('employee_id', $employee->id)->firstOrFail();

    expect($tx->eligible_days)->toBeNull()
        ->and($tx->used_status)->toBe('unknown')
        ->and((float) $tx->applied_days)->toBe(3.0)
        ->and((float) cfaNew($employee, $type)->carried_forward_days)->toBe(3.0)
        ->and(AuditLog::where('action', 'leave.carry_forward_applied')->latest('id')->first()->new_values['carry_forward_decided_by'])->toBe('hr_stated_no_previous_year_record');
});

test('entering again replaces the figure; reversing keeps both in the history', function () {
    [$employee, $type] = cfaSetup();
    $hr = cfaHr();

    cfaDetail($hr, $employee, $type)->set('carryDays', '5')->set('reason', 'First decision')->call('submitAction');
    cfaDetail($hr, $employee, $type)->set('carryDays', '2')->set('reason', 'Revised after review')->call('submitAction')->assertHasNoErrors();

    expect((float) cfaNew($employee, $type)->carried_forward_days)->toBe(2.0)
        ->and(LeaveCarryForwardTransaction::count())->toBe(1);

    $tx = LeaveCarryForwardTransaction::firstOrFail();
    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('startReverseCarryForward', $tx->id)
        ->set('reverseReason', 'Entered against the wrong year')
        ->call('reverseCarryForward')->assertHasNoErrors()
        ->set('tab', 'carry_forward')
        ->assertSee('Entered against the wrong year')
        ->assertSee('Revised after review');

    expect((float) cfaNew($employee, $type)->carried_forward_days)->toBe(0.0)
        ->and($tx->fresh()->status)->toBe('reversed')
        ->and(AuditLog::where('action', 'leave.carry_forward_reversed')->exists())->toBeTrue();
});

test('without the carry-forward permission the action is refused on the server', function () {
    [$employee, $type] = cfaSetup();
    $hr = cfaHr();
    $hr->assignedRole->permissions()->detach(Permission::where('key', 'manage_leave_carry_forward')->value('id'));
    cache()->flush();

    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'carry_forward', $type->id)
        ->assertForbidden();

    expect(LeaveCarryForwardTransaction::count())->toBe(0);
});

test('Leave Management shows a Carry Forward action that opens the dialog for that employee', function () {
    [$employee, $type] = cfaSetup();
    $hr = cfaHr();

    Livewire::actingAs($hr)->test(LeaveManagement::class)
        ->set('leaveTypeId', $type->id)
        ->assertSee('Carry Fwd')
        ->assertSee('cf='.$type->id, false);

    Livewire::withQueryParams(['cf' => $type->id, 'tab' => 'carry_forward'])
        ->actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->assertSet('action', 'carry_forward')
        ->assertSet('formTypeId', $type->id);
});

test('the year-end rollover keeps a carry forward HR already entered', function () {
    [$employee, $type] = cfaSetup();
    cfaDetail(cfaHr(), $employee, $type)->set('carryDays', '4')->set('reason', 'Agreed amount')->call('submitAction');

    $row = app(LeaveRolloverService::class)->preview(cfaYear('2025/26'))
        ->first(fn ($r) => $r['employee_id'] === $employee->id && $r['leave_type_id'] === $type->id);

    expect($row['status'])->toBe('SAFE')
        ->and($row['carry'])->toBe(4.0)
        ->and($row['expire'])->toBe(3.0);

    User::factory()->create(['role' => UserRole::SuperAdmin]);
    app(LeaveRolloverService::class)->process(cfaYear('2025/26'), cfaYear('2026/27'), null);

    expect((float) cfaNew($employee, $type)->carried_forward_days)->toBe(4.0)
        ->and((float) LeaveCarryForwardTransaction::firstOrFail()->applied_days)->toBe(4.0)
        ->and(LeaveCarryForwardTransaction::firstOrFail()->reason)->toBe('Agreed amount');
});
