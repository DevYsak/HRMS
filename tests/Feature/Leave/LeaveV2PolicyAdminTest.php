<?php

use App\Console\Commands\LeaveV2Preview;
use App\Enums\UserRole;
use App\Livewire\TimeOff\LeavePolicyEditor;
use App\Livewire\TimeOff\TimeOffSettings;
use App\Models\AuditLog;
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
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeavePolicyAdminService;
use App\Services\Leave\LeavePolicyAssignmentResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Leave V2 — HR policy administration and the read-only migration preview.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-12-07 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->policy = LeavePolicy::create([
        'name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12,
    ]);
    $make = fn (string $name, string $code) => LeaveType::create([
        'name' => $name, 'code' => $code.random_int(10, 99), 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'counts_toward_annual_cap' => true, 'is_paid' => true, 'allow_paid_request' => true, 'color' => '#10b981',
    ]);
    // The one Earned Leave balance; Casual, Sick and Privilege are reasons.
    $this->earned = $make('Earned Leave', 'EL');
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    $this->casual = $make('Casual Leave', 'CL');
    $this->sick = $make('Sick Leave', 'SL');
    $this->privilege = $make('Privilege Leave', 'PL');
});

test('the editor sets one Earned Leave entitlement against the cap: 12 / 12', function () {
    $component = Livewire::actingAs($this->admin)->test(LeavePolicyEditor::class, ['policyId' => $this->policy->id])
        ->assertSeeHtml('data-earned-policy')
        ->assertSeeHtml('data-policy-reasons')
        ->set('earned.fixed_days', 12)->set('earned.accrual_method', 'monthly')->set('earned.accrual_amount', 1)
        ->set("reasons.{$this->casual->id}.include", true)
        ->set("reasons.{$this->sick->id}.include", true);

    expect($component->instance()->capSummary['allocated'])->toBe(12.0)
        ->and($component->instance()->capSummary['over'])->toBeFalse()
        // Reasons are rows of their own table, never rows of the allocation.
        ->and($component->instance()->otherTypes->pluck('id'))->not->toContain($this->casual->id);
    $component->assertSee('12 / 12')->call('saveRules')->assertHasNoErrors();

    $earnedRule = LeavePolicyRule::where('leave_policy_id', $this->policy->id)->where('leave_type_id', $this->earned->id)->sole();
    expect((float) $earnedRule->fixed_days)->toBe(12.0)
        ->and($earnedRule->accrual_method)->toBe('monthly')
        ->and(LeavePolicyRule::where('leave_policy_id', $this->policy->id)->whereIn('leave_type_id', [$this->casual->id, $this->sick->id])->whereNotNull('fixed_days')->exists())->toBeFalse();

    $component->set('earned.fixed_days', 14)
        ->assertSee('Allocations exceed the cap')
        ->call('saveRules')
        ->assertHasErrors('rules');

    expect((float) $earnedRule->fresh()->fixed_days)->toBe(12.0);
});

test('reasons carry validation rules but no allocation, and every change is audited', function () {
    Livewire::actingAs($this->admin)->test(LeavePolicyEditor::class, ['policyId' => $this->policy->id])
        ->set('earned.fixed_days', 12)
        ->set("reasons.{$this->sick->id}.include", true)
        ->set("reasons.{$this->sick->id}.attachment_after_days", 2)
        ->set("reasons.{$this->sick->id}.hr_after_days", 3)
        ->call('saveRules')
        ->assertHasNoErrors();

    $sickRule = LeavePolicyRule::where('leave_policy_id', $this->policy->id)->where('leave_type_id', $this->sick->id)->sole();

    expect($sickRule->fixed_days)->toBeNull()
        ->and($sickRule->entitlement_method)->toBe(LeavePolicyRule::ENTITLEMENT_NONE)
        ->and((float) $sickRule->attachment_after_days)->toBe(2.0)
        ->and($sickRule->approval_workflow['conditions'][0])->toEqual(['min_days' => 3, 'steps' => ['manager', 'hr']])
        ->and(AuditLog::where('event', 'LEAVE_POLICY_RULE_CREATED')->count())->toBe(2);
});

test('lowering the cap below the Earned Leave entitlement is refused', function () {
    app(LeavePolicyAdminService::class)->saveRules($this->policy, [
        $this->earned->id => ['include' => true, 'fixed_days' => 12],
    ], $this->admin);

    expect(fn () => app(LeavePolicyAdminService::class)->savePolicy(['annual_paid_leave_cap' => 10], $this->policy->id, $this->admin))
        ->toThrow(DomainException::class, 'exceed');
    expect((float) $this->policy->fresh()->annual_paid_leave_cap)->toBe(12.0);
});

test('the Earned Leave balance must be a type in the Earned Leave category', function () {
    $compOff = LeaveType::create(['name' => 'Comp Off', 'code' => 'CO'.random_int(10, 99), 'category' => 'comp_off', 'is_paid' => true, 'color' => '#7c3aed']);

    expect(fn () => app(LeavePolicyAdminService::class)->savePolicy(['earned_leave_type_id' => $compOff->id], $this->policy->id, $this->admin))
        ->toThrow(DomainException::class, 'Earned Leave category');
    expect($this->policy->fresh()->earned_leave_type_id)->toBe($this->earned->id);
});

test('assignments are created and revoked, never deleted, and change the resolved policy', function () {
    $department = Department::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active',
        'department_id' => $department->id, 'manager_id' => null,
    ]);

    Livewire::actingAs($this->admin)->test(LeavePolicyEditor::class, ['policyId' => $this->policy->id])
        ->set('assign.scope', 'department')
        ->set('assign.scope_id', $department->id)
        ->set('assign.effective_from', '2026-07-01')
        ->call('assignPolicy')
        ->assertHasNoErrors()
        ->assertSee('Department: '.$department->name);

    $assignment = LeavePolicyAssignment::sole();
    expect(app(LeavePolicyAssignmentResolver::class)->resolve($employee)['source'])->toBe('department');

    Livewire::actingAs($this->admin)->test(LeavePolicyEditor::class, ['policyId' => $this->policy->id])->call('revoke', $assignment->id);

    expect($assignment->fresh()->revoked_at)->not->toBeNull()
        ->and(LeavePolicyAssignment::count())->toBe(1);
});

test('an employee or department assignment needs its target', function () {
    expect(fn () => app(LeavePolicyAdminService::class)->assign($this->policy, 'department', null, '2026-07-01', null, null, $this->admin))
        ->toThrow(DomainException::class, 'department');
});

test('Leave Settings shows each type V2 role and whether it can be requested', function () {
    $this->casual->update(['description' => 'Reason']);
    $wfh = LeaveType::create(['name' => 'Work From Home', 'code' => 'WFH'.random_int(10, 99), 'category' => 'wfh', 'is_paid' => true,
        'color' => '#64748b', 'availability' => LeaveType::AVAILABILITY_HISTORICAL, 'is_active' => false]);
    $annual = LeaveType::create(['name' => 'Annual Leave', 'code' => 'ANL'.random_int(10, 99), 'category' => 'annual', 'is_paid' => true, 'color' => '#6366f1']);

    $component = Livewire::actingAs($this->admin)->test(TimeOffSettings::class)
        ->assertSeeHtml('data-type-role="Earned balance"')
        ->assertSeeHtml('data-type-role="Earned reason"')
        ->assertSeeHtml('data-type-role="Historical"')
        ->assertSee('Use the WFH module');

    // HR marks an old type Historical only: kept, never offered again.
    $component->call('openModal', $annual->id)->set('availability', LeaveType::AVAILABILITY_HISTORICAL)->call('save')->assertHasNoErrors();

    expect($annual->fresh()->availability)->toBe(LeaveType::AVAILABILITY_HISTORICAL)
        ->and($annual->fresh()->is_active)->toBeFalse()
        ->and($annual->fresh()->isRequestable())->toBeFalse()
        ->and(LeaveType::find($annual->id))->not->toBeNull()
        ->and($wfh->fresh()->isRequestable())->toBeFalse();
});

test('only people who manage settings can open the policy editor', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    $this->actingAs($employee)->get(route('time-off.policies'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('time-off.policies'))->assertOk()->assertSee('Leave Policies');
});

test('the migration preview reconciles balances against the ledger and writes nothing', function () {
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active', 'manager_id' => null,
    ]);
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $this->earned->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 6, Carbon::parse('2026-07-01'), 'preview-test');
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    $counts = fn () => [LeaveBalance::count(), LeaveLedgerEntry::count(), LeaveRequest::count(), LeavePolicyAssignment::count()];
    $before = $counts();

    $this->artisan('leave:v2-preview', ['--year' => '2026/27'])
        ->expectsOutputToContain('READ ONLY')
        ->expectsOutputToContain('RECONCILES')
        ->assertSuccessful();

    expect($counts())->toBe($before);
});

test('a balance whose columns disagree with its ledger is reported STOP', function () {
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active', 'manager_id' => null,
    ]);
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $this->earned->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 6, Carbon::parse('2026-07-01'), 'preview-drift');
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
    // Simulate drift: a stored column no longer matching the ledger (as a legacy write would).
    DB::table('leave_balances')->where('id', $balance->id)->update(['base_days' => 8]);

    $this->artisan('leave:v2-preview', ['--year' => '2026/27', '--only-problems' => true])
        ->expectsOutputToContain('STOP: difference')
        ->assertFailed();
});

/** A ledger-backed balance in 2026/27 holding $days. */
function previewCredit(Employee $employee, LeaveType $type, float $days): LeaveBalance
{
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $type->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, $days, Carbon::parse('2026-07-01'), 'preview-'.$type->id.'-'.$employee->id);

    return app(LeaveLedgerService::class)->rebuild($balance->fresh());
}

/** An employee with no auto-provisioned balances, so the preview sees only what the test creates. */
function previewEmployee(): Employee
{
    return Employee::withoutEvents(fn () => Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active', 'manager_id' => null,
    ]));
}

test('the preview shows Earned Leave, legacy rows and the validation summary, never Casual or Sick as V2 balances', function () {
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    app(LeavePolicyAdminService::class)->saveRules($this->policy, [
        $this->earned->id => ['include' => true, 'fixed_days' => 12, 'accrual_method' => 'monthly'],
        $this->casual->id => ['include' => true],
        $this->sick->id => ['include' => true],
    ], $this->admin);
    LeaveType::create(['name' => 'Bereavement Leave', 'code' => 'BRV'.random_int(10, 99), 'category' => 'bereavement', 'is_paid' => true, 'color' => '#0ea5e9']);
    $legacy = LeaveType::create(['name' => 'Annual Leave (legacy)', 'code' => 'ALX'.random_int(10, 99), 'category' => 'annual', 'is_paid' => true, 'color' => '#6366f1',
        'availability' => LeaveType::AVAILABILITY_HISTORICAL, 'is_active' => false]);

    $employee = previewEmployee();
    previewCredit($employee, $this->earned, 6);
    previewCredit($employee, $legacy, 14);

    $special = LeaveType::get()->filter(fn (LeaveType $t) => LeaveCategory::of($t) === LeaveCategory::SPECIAL)->count();

    $this->artisan('leave:v2-preview', ['--year' => '2026/27', '--employee' => [$employee->id]])
        ->expectsOutputToContain('Policy source')
        ->expectsOutputToContain('V2 role')
        ->expectsOutputToContain(LeaveV2Preview::ROLE_EARNED)
        ->expectsOutputToContain(LeaveV2Preview::ROLE_LEGACY)
        ->expectsOutputToContain('Company Policy')
        ->expectsOutputToContain('Earned Leave usage by reason')
        ->expectsOutputToContain('Annual policy validation — NEXCORE Standard Leave Policy: Earned Leave allocated 12 / 12')
        ->expectsOutputToContain('Reasons: ')
        ->expectsOutputToContain("Special leave excluded: {$special} types (0 balance rows")
        ->expectsOutputToContain('Legacy paid balances: 1 rows')
        ->expectsOutputToContain('Request-vs-ledger mismatches: 0')
        ->assertSuccessful();
});

test('request-vs-ledger mismatches are listed with a known reason and do not block', function () {
    $employee = previewEmployee();
    $wfh = LeaveType::create(['name' => 'Work From Home', 'code' => 'WFH'.random_int(10, 99), 'category' => 'wfh', 'is_paid' => true, 'color' => '#64748b']);
    $casual = LeaveType::create(['name' => 'Casual Leave (old)', 'code' => 'CLO'.random_int(10, 99), 'category' => 'other', 'is_paid' => true, 'color' => '#64748b']);
    // The old calendar-year row: usage written straight to used_days, no leave year, no ledger.
    LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $casual->id, 'allocated_days' => 6, 'used_days' => 3, 'year' => 2026, 'leave_year_id' => null]);
    $request = fn (LeaveType $type, string $from, string $to, float $days) => LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to,
        'days' => $days, 'reason' => 'Before V2', 'requested_leave_status' => 'paid', 'status' => 'approved',
    ]);
    $request($wfh, '2026-07-13', '2026-07-15', 3);
    $request($casual, '2026-07-22', '2026-07-24', 3);

    $this->artisan('leave:v2-preview', ['--year' => '2026/27', '--employee' => [$employee->id]])
        ->expectsOutputToContain('another known compatibility reason')
        ->expectsOutputToContain('old calendar-year bug')
        ->expectsOutputToContain('Request-vs-ledger mismatches: 2 (6 day(s)) — approved paid days 6 vs ledger usage 0')
        ->assertSuccessful();
});

test('an approved request missing from a ledger-backed balance blocks the preview', function () {
    $employee = previewEmployee();
    previewCredit($employee, $this->earned, 6);
    // Approved after the balance moved onto the ledger, yet no usage was posted.
    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $this->earned->id, 'start_date' => '2026-12-08', 'end_date' => '2026-12-08',
        'days' => 1, 'reason' => 'Fever', 'requested_leave_status' => 'paid', 'status' => 'approved', 'approved_at' => now()->addMinute(),
    ]);

    $this->artisan('leave:v2-preview', ['--year' => '2026/27', '--employee' => [$employee->id]])
        ->expectsOutputToContain('missing historical ledger entry')
        ->expectsOutputToContain('Request-vs-ledger mismatches: 1')
        ->assertFailed();
});
