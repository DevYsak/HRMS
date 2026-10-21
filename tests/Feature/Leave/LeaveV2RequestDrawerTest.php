<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\MyLeaveBalances;
use App\Livewire\TimeOff\MyTimeOff;
use App\Livewire\TimeOff\RequestLeave;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeavePolicyEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Leave V2 — the employee Request Leave drawer and My Balances.
 *
 * Step 1 is the main category; for Earned Leave, step 2 shows the one Earned
 * Leave balance and asks for a reason (Casual, Sick…) with no balance beside
 * it. Clock: Monday 7 December 2026, leave year 2026/27.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-12-07 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    // Created before the policy exists, so provisioning grants nothing and
    // the balances below are exactly what the tests credit.
    $this->user = User::factory()->create(['role' => UserRole::Employee]);
    $this->employee = Employee::factory()->create([
        'user_id' => $this->user->id, 'status' => 'active', 'joining_date' => '2024-01-10',
        'manager_id' => null, 'department_id' => Department::factory()->create()->id,
    ]);

    $this->policy = LeavePolicy::create([
        'name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12,
    ]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);

    $make = fn (string $name, string $code, array $extra = []) => LeaveType::create(array_merge([
        'name' => $name, 'code' => $code.random_int(10, 99), 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true, 'color' => '#10b981',
    ], $extra));
    $rule = fn (LeaveType $type, array $extra = []) => LeavePolicyRule::create(array_merge([
        'leave_policy_id' => $this->policy->id, 'leave_type_id' => $type->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE, 'fixed_days' => null,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true,
    ], $extra));

    $this->earned = $make('Earned Leave', 'EL', ['counts_toward_annual_cap' => true]);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    $rule($this->earned, ['entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12, 'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY]);

    $this->reasons = collect(['Casual Leave' => 'CL', 'Sick Leave' => 'SL', 'Privilege Leave' => 'PL', 'Personal Leave' => 'PRL'])
        ->map(fn ($code, $name) => tap($make($name, $code), fn ($type) => $rule($type)));
    $this->sick = $this->reasons['Sick Leave'];
    $this->unpaid = $make('Unpaid Leave', 'LWP', ['leave_category' => LeaveCategory::UNPAID, 'is_paid' => false]);

    $balance = app(LeaveMovementService::class)->balanceFor($this->employee->id, $this->earned->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 8, Carbon::parse('2026-07-01'), 'drawer-earned');
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
});

function drawerEarnedAvailable(): float
{
    $balance = LeaveBalance::where('employee_id', test()->employee->id)->where('leave_type_id', test()->earned->id)->sole();

    return app(LeaveBalanceCalculator::class)->summary($balance)['available_to_request'];
}

test('step 1 offers the main categories with the one Earned Leave balance', function () {
    $component = Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer')
        ->assertSet('open', true)
        ->assertSeeHtml('data-category="earned"')
        ->assertSeeHtml('data-category="unpaid"')
        ->assertDontSeeHtml('data-category="special"')
        ->assertSee('8 days available')
        ->assertSee('No balance required');

    expect(array_keys($component->instance()->choices))->toBe([LeaveCategory::EARNED, LeaveCategory::UNPAID]);
});

test('Earned Leave shows its balance once and the reasons without any balance beside them', function () {
    $component = Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer')
        ->call('chooseCategory', LeaveCategory::EARNED)
        ->call('next')
        ->assertSet('step', 2)
        ->assertSeeHtml('data-earned-available')
        ->assertSee('Earned Leave available')
        ->assertSee('Casual Leave')
        ->assertSee('Sick Leave')
        ->assertSee('Privilege Leave')
        ->assertSee('Personal Leave');

    $html = $component->html();
    foreach ($this->reasons as $reason) {
        $label = str($html)->after('data-reason="'.$reason->code.'"')->before('</option>')->toString();
        expect($label)->toContain($reason->name)->not->toContain('days available');
    }
    expect(substr_count($html, 'days available'))->toBe(0)
        ->and($html)->not->toContain('data-subtype=');

    // The reasons are a dropdown, and one must be chosen before the review.
    expect($html)->toContain('data-reason-select');
    $component->set('startDate', '2026-12-08')->set('endDate', '2026-12-08')->set('reason', 'Fever')
        ->call('next')
        ->assertSet('step', 2)
        ->assertHasErrors('leaveTypeId')
        // Choosing a reason and then the empty "Select a reason…" option again.
        ->set('leaveTypeId', $this->sick->id)
        ->set('leaveTypeId', '')
        ->assertSet('leaveTypeId', null)
        ->call('next')
        ->assertSet('step', 2)
        ->assertHasErrors('leaveTypeId');
});

test('the full flow: Sick Leave for 3 days takes Earned Leave from 8 to 5', function () {
    Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer')
        ->call('chooseCategory', LeaveCategory::EARNED)
        ->call('next')
        ->set('leaveTypeId', $this->sick->id)
        ->set('startDate', '2026-12-08')
        ->set('endDate', '2026-12-10')
        ->set('reason', 'Fever and rest as advised by doctor.')
        ->assertSee('You have 8 days Earned Leave available.')
        ->call('next')
        ->assertSet('step', 3)
        ->assertSee('Reason type')
        ->assertSee('Sick Leave')
        ->assertSee('Earned Leave available before')
        ->assertSee('Remaining after approval')
        ->assertSee('Reporting Manager')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('step', 4)
        ->assertSee('Leave Request Submitted')
        ->assertDispatched('leave-request-submitted');

    $request = LeaveRequest::where('employee_id', $this->employee->id)->sole();
    expect($request->leave_type_id)->toBe($this->sick->id)
        ->and($request->main_category)->toBe(LeaveCategory::EARNED)
        ->and($request->balance_leave_type_id)->toBe($this->earned->id)
        ->and((float) $request->days)->toBe(3.0)
        ->and($request->requested_leave_status)->toBe('paid')
        ->and($request->status)->toBe('pending')
        ->and(drawerEarnedAvailable())->toBe(5.0);
});

test('a request beyond the Earned Leave balance is stopped at the details step', function () {
    Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer')
        ->call('chooseCategory', LeaveCategory::EARNED)
        ->call('next')
        ->set('leaveTypeId', $this->reasons['Casual Leave']->id)
        ->set('startDate', '2026-12-07')
        ->set('endDate', '2026-12-18')
        ->set('reason', 'Long trip')
        ->call('next')
        ->assertSet('step', 2)
        ->assertHasErrors('request')
        ->assertSee('You have only 8 Earned Leave day(s) available.');
});

test('the drawer never asks the employee to choose paid or unpaid', function () {
    $html = Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer')->call('chooseCategory', LeaveCategory::EARNED)->call('next')
        ->set('leaveTypeId', $this->sick->id)->set('startDate', '2026-12-08')
        ->html();

    expect($html)->not->toContain('requested_leave_status')
        ->and($html)->not->toContain('value="unpaid"');
});

test('Special Leave is offered only where the employee is separately entitled', function () {
    $paternity = LeaveType::create([
        'name' => 'Paternity Leave', 'code' => 'PAT'.random_int(10, 99), 'category' => 'paternity',
        'is_paid' => true, 'allow_paid_request' => true, 'color' => '#0ea5e9',
    ]);
    LeavePolicyRule::create([
        'leave_policy_id' => $this->policy->id, 'leave_type_id' => $paternity->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 10,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true,
    ]);

    $component = Livewire::actingAs($this->user)->test(RequestLeave::class)->call('openDrawer');

    expect(array_keys($component->instance()->choices))->toBe([LeaveCategory::EARNED, LeaveCategory::UNPAID, LeaveCategory::SPECIAL])
        // Earned Leave is still only the shared balance.
        ->and($component->instance()->choices[LeaveCategory::EARNED]['available'])->toBe(8.0);
});

test('My Time Off opens the V2 drawer and My Balances shows one Earned Leave balance', function () {
    Livewire::actingAs($this->user)->test(MyTimeOff::class)
        ->call('openRequestModal')
        ->assertDispatched('open-request-leave')
        ->assertSet('showRequestModal', false);

    Livewire::actingAs($this->user)->test(MyLeaveBalances::class)
        ->assertSeeHtml('data-v2-balances')
        ->assertSeeHtml('data-category-card="earned"')
        ->assertSeeHtml('data-earned-detail')
        ->assertSeeHtml('data-usage-by-reason')
        ->assertDontSeeHtml('data-subtype-card')
        ->assertSee('NEXCORE Standard Leave Policy');
});

test('WFH, historical and system types never appear in the request flow', function () {
    LeaveType::create(['name' => 'Work From Home', 'code' => 'WFH'.random_int(10, 99), 'category' => 'wfh', 'is_paid' => true, 'allow_paid_request' => true, 'color' => '#64748b']);
    $oldUnpaid = LeaveType::create(['name' => 'Old Unpaid', 'code' => 'OU'.random_int(10, 99), 'category' => 'unpaid', 'is_paid' => false,
        'allow_unpaid_request' => true, 'color' => '#64748b', 'availability' => LeaveType::AVAILABILITY_HISTORICAL, 'is_active' => false]);
    $systemLwp = LeaveType::create(['name' => 'System LWP', 'code' => 'SLWP'.random_int(10, 99), 'category' => 'lwp', 'is_paid' => false,
        'allow_unpaid_request' => true, 'color' => '#64748b', 'is_system_controlled' => true]);

    $component = Livewire::actingAs($this->user)->test(RequestLeave::class)->call('openDrawer');
    $offered = collect($component->instance()->choices)->flatMap(fn ($c) => collect($c['types'])->pluck('id'));

    expect($offered)->toContain($this->unpaid->id)
        ->and($offered)->not->toContain($oldUnpaid->id)
        ->and($offered)->not->toContain($systemLwp->id)
        ->and($component->html())->not->toContain('Work From Home');
});

test('My Time Off shows the V2 policy, not CSL and MDL', function () {
    Livewire::actingAs($this->user)->test(MyTimeOff::class)
        ->assertSee('NEXCORE Standard Leave Policy')
        ->assertSee('Available Paid Leave')
        ->assertSeeInOrder(['Policy Overview', 'Earned Leave', '12 days/year'])
        ->assertDontSee('CSL +')
        ->assertDontSee('CSL carry forward');
});

test('the My Leave page follows the redesigned order with one Earned Leave figure per place', function () {
    $component = Livewire::actingAs($this->user)->test(MyTimeOff::class)
        ->assertSeeInOrder([
            'Manage your leave balance, requests and upcoming time off.',
            'Available Paid Leave', 'Pending Requests', 'Approved / Taken This Year', 'Next Holiday',
            'My Balances', 'Pending &amp; Upcoming', 'Leave History', 'Calendar',
        ], false)
        ->assertSeeHtml('data-policy-line')
        ->assertSeeHtml('data-quick-actions')
        ->assertSeeHtml('data-planning')
        ->assertSeeHtml('data-policy-overview')
        ->assertSeeHtml('data-leave-insights')
        ->assertSeeHtml('data-holiday-schedule')
        ->assertSeeHtml('data-recent-applications')
        ->assertSee('Recent Leave Applications')
        ->assertSee('Current policy')
        // The five overview tiles and the Policy Explorer are now one card.
        ->assertDontSee('Policy Explorer')
        ->assertDontSee('Earned Leave available');

    $html = $component->html();
    expect(strpos($html, 'data-leave-tabs'))->toBeLessThan(strpos($html, 'data-quick-actions'))
        ->and(strpos($html, 'data-quick-actions'))->toBeLessThan(strpos($html, 'data-planning'))
        ->and(strpos($html, 'data-planning'))->toBeLessThan(strpos($html, 'data-policy-overview'))
        ->and(strpos($html, 'data-policy-overview'))->toBeLessThan(strpos($html, 'data-leave-insights'))
        ->and(strpos($html, 'data-leave-insights'))->toBeLessThan(strpos($html, 'data-recent-applications'));
});

test('embedded balances follow the page tabs and keep historical balances visible', function () {
    $old = LeaveType::create([
        'name' => 'Annual Leave (old policy)', 'code' => 'AOL'.random_int(10, 99), 'category' => 'annual', 'is_paid' => true,
        'allow_paid_request' => true, 'color' => '#64748b', 'availability' => LeaveType::AVAILABILITY_HISTORICAL, 'is_active' => false,
    ]);
    $balance = app(LeaveMovementService::class)->balanceFor($this->employee->id, $old->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 4, Carbon::parse('2026-07-01'), 'drawer-historical');
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    Livewire::actingAs($this->user)->test(MyLeaveBalances::class, ['embedded' => true])
        // The page owns the tab bar; the component keeps only its year picker.
        ->assertDontSee('Transaction History')
        ->assertSeeHtml('data-historical-balances')
        ->assertSee('Historical Leave Balances')
        ->assertSee('They cannot be used for new requests.')
        ->assertSee('Annual Leave (old policy)')
        ->assertSee('No Earned Leave has been used')
        ->assertSee('You have not taken any Earned Leave this leave year.')
        ->dispatch('leave-panel', panel: 'requests')
        ->assertSet('panel', 'requests')
        ->assertSeeHtml('data-leave-balances-panel="requests"')
        ->dispatch('leave-panel', panel: 'nonsense')
        ->assertSet('panel', 'requests');
});

test('Leave History keeps every request, with paid and unpaid days and a Legacy badge on pre-V2 rows', function () {
    $legacy = LeaveRequest::create([
        'employee_id' => $this->employee->id, 'leave_type_id' => $this->sick->id,
        'start_date' => '2026-08-03', 'end_date' => '2026-08-04', 'days' => 2,
        'reason' => 'Before V2', 'status' => 'cancelled',
    ]);
    $current = LeaveRequest::create([
        'employee_id' => $this->employee->id, 'leave_type_id' => $this->reasons['Casual Leave']->id,
        'main_category' => LeaveCategory::EARNED, 'balance_leave_type_id' => $this->earned->id,
        'start_date' => '2026-12-14', 'end_date' => '2026-12-16', 'days' => 3, 'lwp_days' => 1,
        'reason' => 'Family', 'status' => 'pending',
    ]);

    $html = Livewire::actingAs($this->user)->test(MyTimeOff::class)
        ->assertSee('LR-'.str_pad((string) $legacy->id, 4, '0', STR_PAD_LEFT))
        ->assertSee('LR-'.str_pad((string) $current->id, 4, '0', STR_PAD_LEFT))
        ->assertSeeInOrder(['Request ID', 'Leave Category', 'Reason / Type', 'Date Range', 'Days', 'Paid', 'Unpaid', 'Status', 'Approver', 'Applied On', 'Actions'])
        ->assertSeeHtml('data-legacy-badge')
        ->assertSeeHtml('data-history-cards')
        ->assertSee('View Request')
        ->assertSee('Request Another Leave')
        ->html();

    // Display only: the stored request is untouched.
    expect($current->fresh()->days)->toEqual('3.00')
        ->and($current->fresh()->lwp_days)->toEqual('1.00')
        ->and(substr_count($html, 'data-legacy-badge'))->toBe(1);
});

test('a special balance granted under an earlier policy shows what was granted, with the policy figure apart', function () {
    $bereavement = LeaveType::create([
        'name' => 'Bereavement Leave', 'code' => 'BRV'.random_int(10, 99), 'category' => 'bereavement',
        'is_paid' => true, 'allow_paid_request' => true, 'color' => '#0ea5e9',
    ]);
    LeavePolicyRule::create([
        'leave_policy_id' => $this->policy->id, 'leave_type_id' => $bereavement->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 5,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true,
    ]);
    // Granted 10 before the employee moved to this policy; never rewritten.
    $balance = app(LeaveMovementService::class)->balanceFor($this->employee->id, $bereavement->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 10, Carbon::parse('2026-07-01'), 'drawer-brv');
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    Livewire::actingAs($this->user)->test(MyLeaveBalances::class)
        ->assertSeeHtml('data-special-card="'.$bereavement->code.'"')
        ->assertSee('10 days granted')
        ->assertSee('Policy entitlement: 5 days a year')
        ->assertDontSee('/ 5 days');
});

test('with the flag off My Time Off keeps its existing request form', function () {
    config(['leave_provisioning.policy_engine_v2' => false]);

    Livewire::actingAs($this->user)->test(MyTimeOff::class)
        ->call('openRequestModal')
        ->assertNotDispatched('open-request-leave')
        ->assertSet('showRequestModal', true);

    Livewire::actingAs($this->user)->test(MyLeaveBalances::class)
        ->assertDontSeeHtml('data-v2-balances');
});

test('with the flag on, an employee whose policy has no Earned Leave keeps the legacy balance cards', function () {
    $this->policy->update(['earned_leave_type_id' => null]);

    Livewire::actingAs($this->user)->test(MyLeaveBalances::class, ['embedded' => true])
        ->assertDontSeeHtml('data-v2-balances')
        ->assertDontSee('Earned Leave is not set up for your policy yet.');
});

test('reopening the drawer clears everything from a cancelled request', function () {
    $component = Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer')
        ->call('chooseCategory', LeaveCategory::EARNED)
        ->set('leaveTypeId', $this->sick->id)
        ->set('startDate', '2026-12-14')
        ->set('endDate', '2026-12-15')
        ->set('reason', 'Feeling unwell')
        ->set('remarks', 'Old remark')
        ->call('next')
        ->call('close')
        ->call('openDrawer');

    $component->assertSet('open', true)
        ->assertSet('step', 1)
        ->assertSet('category', '')
        ->assertSet('leaveTypeId', null)
        ->assertSet('startDate', '')
        ->assertSet('endDate', '')
        ->assertSet('reason', '')
        ->assertSet('remarks', '')
        ->assertSet('allocations', [])
        ->assertSet('loadError', null)
        ->assertHasNoErrors();
});

test('the drawer opens with a clear message when the leave policy cannot load', function () {
    Log::spy();
    $this->mock(LeavePolicyEngine::class, fn ($mock) => $mock->shouldReceive('balances')->andThrow(new RuntimeException('policy lookup failed')));

    Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer')
        ->assertSet('open', true)
        ->assertSet('loadError', RequestLeave::LOAD_ERROR)
        ->assertSee("We couldn't load your current leave policy. Please contact HR.")
        ->assertSeeHtml('data-request-leave-error')
        ->assertDontSee('Next');

    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => str_contains($message, 'leave policy could not be loaded')
        && $context['employee_id'] === $this->employee->id && $context['message'] === 'policy lookup failed');
});

test('dates being typed or ranges over a year never start a day-by-day evaluation', function () {
    $component = Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer')
        ->call('chooseCategory', LeaveCategory::EARNED)
        ->call('next')
        ->set('leaveTypeId', $this->sick->id)
        // What a date input sends while "2026" is typed into the year.
        ->set('startDate', '0002-12-14')
        ->set('endDate', '2026-12-15');

    expect($component->instance()->evaluation)->toBeNull();

    $component->set('startDate', '2026-12-14')->set('endDate', '2028-12-15')->set('reason', 'A very long trip')->call('next')
        ->assertHasErrors('endDate')
        ->assertSet('step', 2);

    expect(LeaveYear::count())->toBe(1);
});

test('the planner opens the drawer with both its dates', function () {
    Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer', null, '2026-12-14', '2026-12-16')
        ->assertSet('startDate', '2026-12-14')
        ->assertSet('endDate', '2026-12-16');

    Livewire::actingAs($this->user)->test(MyTimeOff::class)
        ->set('planner_start', '2026-12-14')
        ->set('planner_end', '2026-12-16')
        ->call('applyFromPlanner')
        ->assertDispatched('open-request-leave', date: '2026-12-14', endDate: '2026-12-16');
});

test('with the flag on, a policy without Earned Leave keeps the existing form instead of an empty drawer', function () {
    $this->policy->update(['earned_leave_type_id' => null]);

    Livewire::actingAs($this->user)->test(MyTimeOff::class)
        ->call('openRequestModal')
        ->assertNotDispatched('open-request-leave')
        ->assertSet('showRequestModal', true)
        ->assertDontSeeHtml('data-request-leave-loading');
});

test('arriving with ?request=1 opens Request Leave', function () {
    Livewire::actingAs($this->user)->withQueryParams(['request' => 1])->test(MyTimeOff::class)
        ->assertDispatched('open-request-leave');
});
