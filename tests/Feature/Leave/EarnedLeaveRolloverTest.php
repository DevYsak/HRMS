<?php

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveRolloverRecord;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\EarnedLeaveAccrualService;
use App\Services\Leave\EarnedLeaveRolloverService as Rollover;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveRolloverService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Leave V2 Earned Leave year-end: after the final June accrual, carry the
 * unused Earned Leave into the new Jul–Jun year as the policy says — once,
 * under one key — and leave the closing year untouched.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-07-02 10:00:00'));
    $this->old = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    $this->new = LeaveYear::firstOrCreate(['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30'], ['label' => '2027/28']);

    $this->policy = LeavePolicy::create(['name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    $this->earned = LeaveType::create(['name' => 'Earned Leave', 'code' => 'ELV2', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'counts_toward_annual_cap' => true, 'color' => '#10b981']);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    $this->rule = LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->earned->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12,
        'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY, 'accrual_amount' => 1, 'joining_month_rule' => LeavePolicyRule::JOINING_HALF_MONTH,
        'carry_forward_enabled' => true, 'carry_forward_max_days' => 6, 'is_active' => true]);

    $user = User::factory()->create(['role' => UserRole::Employee]);
    $this->employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-10', 'employee_id' => 'RO-001',
        'manager_id' => null, 'department_id' => Department::factory()->create()->id]);
});

function roAvailable(LeaveYear $year): float
{
    $balance = LeaveBalance::where('employee_id', test()->employee->id)->where('leave_type_id', test()->earned->id)->where('leave_year_id', $year->id)->first();

    return $balance ? (float) app(LeaveBalanceCalculator::class)->summary($balance)['approved_available'] : 0.0;
}

/** Accrue the whole closing year (12) and take $used days of it. */
function roYear(float $used = 4, string $asOf = '2027-07-01'): void
{
    $accrual = app(EarnedLeaveAccrualService::class);
    $accrual->apply($accrual->planFor(test()->employee->fresh(), test()->old, Carbon::parse($asOf)));
    if ($used > 0) {
        $balance = LeaveBalance::where('employee_id', test()->employee->id)->where('leave_type_id', test()->earned->id)->where('leave_year_id', test()->old->id)->sole();
        app(LeaveLedgerService::class)->debit($balance, LeaveLedgerEntry::TYPE_USAGE, $used, Carbon::parse('2027-03-10'), 'ro-used-'.uniqid());
        app(LeaveLedgerService::class)->rebuild($balance->fresh());
    }
}

function roPlan(): array
{
    return app(Rollover::class)->planFor(test()->employee->fresh(), test()->old, test()->new);
}

test('after the June accrual, unused Earned Leave carries up to the policy maximum — once', function () {
    roYear(used: 4);
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));
    expect(roAvailable($this->old))->toEqual(8.0);
    $oldLedger = LeaveLedgerEntry::where('leave_year_id', $this->old->id)->pluck('id')->all();

    expect(roPlan())->toMatchArray(['status' => Rollover::READY, 'closing' => 8.0, 'carry' => 6.0, 'lapse' => 2.0, 'cap' => 6.0]);
    expect(app(Rollover::class)->apply(roPlan())['status'])->toBe('ROLLED OVER');

    $carry = LeaveLedgerEntry::where('leave_year_id', $this->new->id)->where('employee_id', $this->employee->id)->sole();
    expect(roAvailable($this->new))->toEqual(6.0)
        ->and($carry->entry_type)->toBe(LeaveLedgerEntry::TYPE_CARRY_FORWARD)
        ->and($carry->idempotency_key)->toBe("earned-carry-forward:{$this->employee->id}:{$this->old->id}:{$this->new->id}")
        ->and($carry->effective_date->toDateString())->toBe('2027-07-01')
        // The closing year is untouched.
        ->and(LeaveLedgerEntry::where('leave_year_id', $this->old->id)->pluck('id')->all())->toBe($oldLedger)
        ->and(roAvailable($this->old))->toEqual(8.0);

    // Again: nothing more.
    expect(roPlan()['status'])->toBe(Rollover::DONE);
    app(Rollover::class)->apply(roPlan());
    expect(LeaveLedgerEntry::where('leave_year_id', $this->new->id)->count())->toBe(1)
        ->and(LeaveRolloverRecord::where('employee_id', $this->employee->id)->count())->toBe(1);
});

test('the new year starts from the carry and accrues month by month', function () {
    roYear(used: 4);
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));
    app(Rollover::class)->apply(roPlan());

    $this->travelTo(Carbon::parse('2027-08-01 00:20:00'));
    $accrual = app(EarnedLeaveAccrualService::class);
    $row = $accrual->planFor($this->employee->fresh(), $this->new, Carbon::parse('2027-08-01'));
    expect($row)->toMatchArray(['status' => EarnedLeaveAccrualService::READY, 'due_months' => ['2027-07']]);
    $accrual->apply($row);

    expect(roAvailable($this->new))->toEqual(7.0);
});

test('the rollover waits for the final June accrual and refuses an unfinished year', function () {
    roYear(used: 0, asOf: '2027-06-15');
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));
    expect(roPlan())->toMatchArray(['status' => Rollover::REVIEW])
        ->and(roPlan()['reason'])->toContain('2027-06');

    $this->travelTo(Carbon::parse('2027-06-20 10:00:00'));
    expect(roPlan()['status'])->toBe(Rollover::BLOCKED);
});

test('carry forward without a configured maximum is blocked, never unlimited', function () {
    $this->rule->update(['carry_forward_max_days' => null]);
    roYear();
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));

    expect(roPlan())->toMatchArray(['status' => Rollover::BLOCKED])
        ->and(roPlan()['reason'])->toContain('no maximum is configured');
});

test('with carry forward off nothing carries, the new year opens once', function () {
    $this->rule->update(['carry_forward_enabled' => false]);
    roYear();
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));

    expect(roPlan())->toMatchArray(['status' => Rollover::READY, 'carry' => 0.0, 'lapse' => 8.0]);
    app(Rollover::class)->apply(roPlan());

    expect(LeaveLedgerEntry::where('leave_year_id', $this->new->id)->count())->toBe(0)
        ->and(LeaveBalance::where('employee_id', $this->employee->id)->where('leave_type_id', $this->earned->id)->where('leave_year_id', $this->new->id)->exists())->toBeTrue()
        ->and(roPlan()['status'])->toBe(Rollover::DONE);
});

test('a pending request in the closing year stops the rollover for that employee', function () {
    roYear();
    LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->earned->id, 'main_category' => LeaveCategory::EARNED,
        'balance_leave_type_id' => $this->earned->id, 'start_date' => '2027-06-28', 'end_date' => '2027-06-28', 'days' => 1,
        'reason' => 'Late request', 'status' => 'pending', 'requested_leave_status' => 'paid']);
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));

    expect(roPlan()['status'])->toBe(Rollover::REVIEW);
});

test('the generic rollover never carries Earned Leave or a migrated employee\'s CSL', function () {
    roYear();
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));

    $types = app(LeaveRolloverService::class)->preview($this->old, $this->new, ['employee_id' => $this->employee->id])->pluck('leave_type');
    expect($types)->not->toContain('Earned Leave');
});

test('leave:rollover previews the Earned Leave year-end without writing, and applies it', function () {
    roYear();
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));
    $before = LeaveLedgerEntry::count();

    $this->artisan('leave:rollover', ['--from' => '2026/27', '--preview' => true, '--earned-only' => true])
        ->expectsOutputToContain('PREVIEW — nothing is written')->assertSuccessful();
    expect(LeaveLedgerEntry::count())->toBe($before);

    $this->artisan('leave:rollover', ['--from' => '2026/27', '--apply' => true, '--earned-only' => true])->assertSuccessful();
    expect(roAvailable($this->new))->toEqual(6.0);
});
