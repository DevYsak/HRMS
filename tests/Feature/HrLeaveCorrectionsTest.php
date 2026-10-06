<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\EmployeeLeaveDetail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveAdminActionService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\LeaveService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * HR leave corrections: record approved leave, correct or cancel approved
 * leave, reverse one ledger movement — always through the ledger, with a
 * reason, a confirmation for high-impact changes, an optional private
 * document, an audit event, and nothing ever deleted.
 *
 * Clock: Wednesday 14 October 2026, inside leave year 2026/27.
 */
beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'], ['label' => '2025/26']);
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    $this->seed(RolesAndPermissionsSeeder::class);
});

function hlcHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => Role::where('slug', 'hr_admin')->firstOrFail()->id]);
}

/** An employee on a policy granting 12 days of a paid type. */
function hlcSetup(): array
{
    $policy = LeavePolicy::create([
        'name' => 'Corrections '.random_int(100, 999), 'statutory_weeks' => 5.6, 'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => 'additional', 'irregular_accrual_rate' => 0.1207, 'is_default' => false, 'is_active' => true,
    ]);
    $type = LeaveType::create([
        'name' => 'Casual Leave', 'code' => 'H'.random_int(10000, 99999), 'category' => 'other',
        'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true,
    ]);
    LeavePolicyRule::create(['leave_policy_id' => $policy->id, 'leave_type_id' => $type->id, 'entitlement_method' => 'fixed_days', 'fixed_days' => 12]);

    $user = User::factory()->create(['role' => UserRole::Employee, 'role_id' => Role::where('slug', 'employee')->firstOrFail()->id]);
    $employee = Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'leave_policy_id' => $policy->id, 'joining_date' => '2024-02-01',
    ]);

    return [$employee, $type];
}

function hlcAvailable(Employee $employee, LeaveType $type): float
{
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2026)->firstOrFail();

    return app(LeaveBalanceCalculator::class)->summary($balance->fresh())['approved_available'];
}

/** Two days (Mon–Tue 19–20 Oct) recorded as approved by HR. */
function hlcApproved(Employee $employee, LeaveType $type, User $hr): LeaveRequest
{
    return app(LeaveAdminActionService::class)->recordApproved($hr, $employee, $type, '2026-10-19', '2026-10-20', 'Recorded from the paper form');
}

test('HR records leave as already approved: usage is posted and audited', function () {
    [$employee, $type] = hlcSetup();
    $hr = hlcHr();

    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'apply', $type->id)
        ->set('startDate', '2026-10-19')->set('endDate', '2026-10-20')
        ->set('reason', 'Recorded from the paper form')->set('recordApproved', true)
        ->call('submitAction')
        ->assertHasNoErrors();

    $request = LeaveRequest::where('employee_id', $employee->id)->firstOrFail();
    expect($request->status)->toBe('approved')
        ->and($request->applied_by_user_id)->toBe($hr->id)
        ->and(hlcAvailable($employee, $type))->toBe(10.0)
        ->and(AuditLog::where('event', 'LEAVE_APPROVED')->where('subject_employee_id', $employee->id)->exists())->toBeTrue();
});

test('recording approved leave needs its own permission', function () {
    [$employee, $type] = hlcSetup();
    $hr = hlcHr();
    $hr->assignedRole->permissions()->detach(Permission::where('key', 'record_approved_leave')->value('id'));
    cache()->flush();

    expect(fn () => hlcApproved($employee, $type, $hr->fresh()))->toThrow(AuthorizationException::class);
    expect(LeaveRequest::count())->toBe(0);
});

test('cancelling approved leave returns the days, keeps the history and records the reason and document', function () {
    [$employee, $type] = hlcSetup();
    $hr = hlcHr();
    $request = hlcApproved($employee, $type, $hr);
    expect(hlcAvailable($employee, $type))->toBe(10.0);

    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openRequestAction', 'cancel_leave', $request->id)
        ->set('reason', 'Employee worked those days')
        ->set('attachment', UploadedFile::fake()->create('timesheet.pdf', 40, 'application/pdf'))
        ->call('submitAction')
        ->assertHasErrors('confirmed')
        ->set('confirmed', true)
        ->call('submitAction')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('cancelled')
        ->and(hlcAvailable($employee, $type))->toBe(12.0);

    // Usage and its reversal both remain.
    $entries = LeaveLedgerEntry::where('employee_id', $employee->id)->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->whereNotNull('reverses_entry_id'))->toHaveCount(1);

    $audit = AuditLog::where('event', 'LEAVE_CANCELLED')->where('subject_employee_id', $employee->id)->latest('id')->first();
    expect($audit->reason)->toBe('Employee worked those days')
        ->and($audit->user_id)->toBe($hr->id)
        ->and($audit->new_values['document'])->toStartWith("leave-documents/{$employee->id}/");
    Storage::disk('local')->assertExists($audit->new_values['document']);
});

test('correcting approved leave reverses the old days and posts the new ones', function () {
    [$employee, $type] = hlcSetup();
    $hr = hlcHr();
    $request = hlcApproved($employee, $type, $hr);

    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openRequestAction', 'correct_leave', $request->id)
        ->assertSet('startDate', '2026-10-19')
        ->set('endDate', '2026-10-19')
        ->set('reason', 'Came back a day early')
        ->set('confirmed', true)
        ->call('submitAction')
        ->assertHasNoErrors();

    expect((float) $request->fresh()->days)->toBe(1.0)
        ->and($request->fresh()->status)->toBe('approved')
        ->and(hlcAvailable($employee, $type))->toBe(11.0)
        ->and(AuditLog::where('event', 'LEAVE_DECISION_CHANGED')->where('reason', 'Came back a day early')->exists())->toBeTrue();
});

test('an HR movement such as an add-on lot can be reversed once; usage cannot', function () {
    [$employee, $type] = hlcSetup();
    $hr = hlcHr();

    $component = Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'add', $type->id)
        ->set('days', '2')->set('addOnType', 'comp_off_credit')->set('reason', 'Worked the Saturday shutdown')
        ->call('submitAction')->assertHasNoErrors();
    expect(hlcAvailable($employee, $type))->toBe(14.0);

    $lot = LeaveLedgerEntry::where('employee_id', $employee->id)->where('entry_type', LeaveLedgerEntry::TYPE_ADD_ON)->firstOrFail();

    $component->set('tab', 'history')->assertSee('Reverse')
        ->call('openReverseEntry', $lot->id)
        ->set('reason', 'Credited to the wrong employee')->set('confirmed', true)
        ->call('submitAction')->assertHasNoErrors();

    expect(hlcAvailable($employee, $type))->toBe(12.0)
        ->and(LeaveLedgerEntry::where('reverses_entry_id', $lot->id)->exists())->toBeTrue()
        ->and(AuditLog::where('event', 'LEAVE_LEDGER_ENTRY_REVERSED')->exists())->toBeTrue();

    // Twice: refused by the ledger.
    expect(fn () => app(LeaveAdminActionService::class)->reverseEntry($lot->fresh(), $hr, 'Again'))->toThrow(DomainException::class);

    // Usage belongs to a leave request: cancel the request instead.
    $request = hlcApproved($employee, $type, $hr);
    $usage = LeaveLedgerEntry::where('source_id', $request->id)->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->firstOrFail();
    expect(fn () => app(LeaveAdminActionService::class)->reverseEntry($usage, $hr, 'Wrong'))->toThrow(DomainException::class, 'Cancel the leave request');
});

test('a deduction keeps its effective date and a private supporting document', function () {
    [$employee, $type] = hlcSetup();

    Livewire::actingAs(hlcHr())->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'deduct', $type->id)
        ->set('days', '1')->set('effectiveDate', '2026-09-02')->set('reason', 'Unrecorded absence')
        ->set('attachment', UploadedFile::fake()->create('note.pdf', 20, 'application/pdf'))
        ->set('confirmed', true)
        ->call('submitAction')->assertHasNoErrors();

    $adjustment = LeaveBalanceAdjustment::where('employee_id', $employee->id)->latest('id')->firstOrFail();
    expect($adjustment->effective_date->toDateString())->toBe('2026-09-02')
        ->and($adjustment->document_path)->toStartWith("leave-documents/{$employee->id}/");
    Storage::disk('local')->assertExists($adjustment->document_path);
});

test('correcting or cancelling approved leave needs manage_approved_leave', function () {
    [$employee, $type] = hlcSetup();
    $hr = hlcHr();
    $request = hlcApproved($employee, $type, $hr);
    $hr->assignedRole->permissions()->detach(Permission::where('key', 'manage_approved_leave')->value('id'));
    cache()->flush();

    Livewire::actingAs($hr->fresh())->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openRequestAction', 'cancel_leave', $request->id)
        ->assertForbidden();

    expect($request->fresh()->status)->toBe('approved');
});

test('HR cannot cancel their own approved leave', function () {
    [, $type] = hlcSetup();
    $hr = hlcHr();
    $self = Employee::factory()->create(['user_id' => $hr->id, 'status' => 'active']);
    $request = LeaveRequest::create([
        'employee_id' => $self->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-19', 'end_date' => '2026-10-19',
        'days' => 1, 'reason' => 'Mine', 'status' => 'approved', 'requested_leave_status' => 'unpaid',
    ]);

    expect(fn () => app(LeaveAdminActionService::class)->cancelApproved($request, $hr, 'Mine'))->toThrow(DomainException::class, 'your own leave');
});

test('HR second-stage approval posts usage through the ledger', function () {
    [$employee, $type] = hlcSetup();
    $hr = hlcHr();
    $request = LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-19', 'end_date' => '2026-10-19',
        'days' => 1, 'reason' => 'Appointment', 'status' => 'pending_hr', 'requested_leave_status' => 'paid',
    ]);

    app(LeaveService::class)->hrApproveRequest($request, $hr->id, 'approved', 'OK');

    expect(LeaveLedgerEntry::where('source_id', $request->id)->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->exists())->toBeTrue()
        ->and(hlcAvailable($employee, $type))->toBe(11.0);
});

test('the All Time Off new-request form is apply-on-behalf: permission, actor and audit', function () {
    [$employee, $type] = hlcSetup();
    $manager = User::factory()->create(['role' => UserRole::Manager, 'role_id' => Role::where('slug', 'manager')->firstOrFail()->id]);
    $employee->update(['manager_id' => $manager->id]);

    Livewire::actingAs($manager)->test(AllTimeOff::class)->call('openNewModal')->assertForbidden();

    $hr = hlcHr();
    Livewire::actingAs($hr)->test(AllTimeOff::class)
        ->call('openNewModal')
        ->set('newForm.employee_id', $employee->id)->set('newForm.leave_type_id', $type->id)
        ->set('newForm.start_date', '2026-10-21')->set('newForm.end_date', '2026-10-21')
        ->set('newForm.reason', 'Phoned in')
        ->call('submitNewRequest')
        ->assertHasNoErrors();

    $request = LeaveRequest::where('employee_id', $employee->id)->firstOrFail();
    expect($request->applied_by_user_id)->toBe($hr->id)
        ->and(AuditLog::where('event', 'LEAVE_APPLIED_ON_BEHALF')->where('subject_employee_id', $employee->id)->exists())->toBeTrue();
});
