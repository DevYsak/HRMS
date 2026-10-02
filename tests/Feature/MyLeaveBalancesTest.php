<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\MyLeaveBalances;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveLedgerService;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Phase 2E — the employee's own leave dashboard.
 *
 * Clock: 15 August 2026, inside leave year 2026/27.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-15 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'], ['label' => '2025/26']);
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
});

/** Base 20, carry 3 (expiring 30 Sep), add-on 2, accrual 1, used 6, pending 2. */
function mlbSetup(): array
{
    $policy = LeavePolicy::create([
        'name' => 'Mine '.random_int(100, 999), 'statutory_weeks' => 5.6, 'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => 'additional', 'irregular_accrual_rate' => 0.1207, 'is_default' => false, 'is_active' => true,
    ]);
    $type = LeaveType::create(['name' => 'Annual Holiday', 'code' => 'H'.random_int(10000, 99999), 'category' => 'other', 'is_paid' => true, 'allow_paid_request' => true]);
    LeavePolicyRule::create(['leave_policy_id' => $policy->id, 'leave_type_id' => $type->id, 'entitlement_method' => 'fixed_days', 'fixed_days' => 20]);

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'leave_policy_id' => $policy->id, 'joining_date' => '2024-01-08',
    ]);

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2026)->firstOrFail();
    $ledger = app(LeaveLedgerService::class);
    $ledger->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 3, Carbon::parse('2026-07-01'), 'test:cf:'.$balance->id, ['expires_on' => '2026-09-30']);
    $ledger->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, 1, Carbon::parse('2026-08-01'), 'test:acc:'.$balance->id);
    $ledger->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 6, Carbon::parse('2026-07-20'), 'test:use:'.$balance->id);
    $ledger->rebuild($balance);

    app(LeaveBalanceService::class)->adjust(
        $employee, $type, 'credit', 2, 'Project milestone bonus days', '', User::factory()->create(['role' => UserRole::HrAdmin]),
        2026, LeaveBalanceService::CATEGORY_ADD_ON, 'management_grant', internalNote: 'Confidential: retention risk',
    );

    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-09-14', 'end_date' => '2026-09-15',
        'days' => 2, 'reason' => 'Trip', 'requested_leave_status' => 'paid', 'status' => 'pending_hr',
    ]);

    return [$employee, $type];
}

test('each card separates every bucket and shows available-to-request', function () {
    [$employee, $type] = mlbSetup();

    $card = Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)
        ->get('cards')->first(fn ($c) => $c['type']->id === $type->id)['summary'];

    expect($card['base'])->toBe(20.0)
        ->and($card['carry_forward'])->toBe(3.0)
        ->and($card['add_on'])->toBe(2.0)
        ->and($card['accrued'])->toBe(1.0)
        ->and($card['used'])->toBe(6.0)
        ->and($card['pending'])->toBe(2.0)
        ->and($card['approved_available'])->toBe(20.0)
        ->and($card['available_to_request'])->toBe(18.0);

    Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)
        ->assertSee('Annual Holiday')->assertSee('Available to request')->assertSee('01 Jul 2026 – 30 Jun 2027');
});

test('an expiry alert names the days and the date', function () {
    [$employee] = mlbSetup();

    // Usage came out of carry forward first, so none is left to expire here.
    Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)->assertDontSee('expire on 30 Sep 2026');

    [$fresh, $type] = mlbSetup();
    $balance = LeaveBalance::where('employee_id', $fresh->id)->where('leave_type_id', $type->id)->first();
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, 4, Carbon::parse('2026-08-01'), 'test:exp:'.$balance->id, ['expires_on' => '2026-09-30']);

    Livewire::actingAs($fresh->user)->test(MyLeaveBalances::class)
        ->assertSee('expire on')->assertSee('30 Sep 2026');
});

test('history is the employee\'s own and never shows HR internal notes', function () {
    [$employee] = mlbSetup();
    [$someoneElse] = mlbSetup();

    Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)
        ->set('panel', 'history')
        ->assertSee('Base Entitlement')->assertSee('Carry Forward')->assertSee('Add-On Leave')
        ->assertSee('Project milestone bonus days')
        ->assertDontSee('retention risk');

    expect(Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)->get('history')
        ->every(fn ($row) => LeaveLedgerEntry::find($row['id'])->employee_id === $employee->id))->toBeTrue();
});

test('the month-wise statement opens, credits, uses and closes per month', function () {
    [$employee, $type] = mlbSetup();

    $months = Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)
        ->call('showStatement', $type->id)
        ->assertSee('July 2026')
        ->get('statement');

    expect($months->firstWhere('month', '2026-07')['opening'])->toBe(23.0)
        ->and($months->firstWhere('month', '2026-07')['used'])->toBe(6.0)
        ->and($months->firstWhere('month', '2026-08')['credits'])->toBe(3.0);
});

test('pending requests show their current stage', function () {
    [$employee] = mlbSetup();

    Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)
        ->set('panel', 'requests')
        ->assertSee('Awaiting HR')
        ->assertSee('14 Sep 2026');
});

test('Apply Leave on a card opens My Time Off\'s request form for that type', function () {
    [$employee, $type] = mlbSetup();

    Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)
        ->call('apply', $type->id)
        ->assertDispatched('apply-leave', leaveTypeId: $type->id);

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)
        ->call('applyForType', $type->id)
        ->assertSet('showRequestModal', true)
        ->assertSet('leave_type_id', (string) $type->id);
});
