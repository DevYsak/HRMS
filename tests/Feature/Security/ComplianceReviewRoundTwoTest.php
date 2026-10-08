<?php

use App\Enums\UserRole;
use App\Livewire\ApprovalCenter;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\Onboarding\OffboardingManager;
use App\Livewire\Payroll\FinanceApproval;
use App\Livewire\Profile\EmployeeProfile;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\EmployeeScorecard;
use App\Models\ExitRecord;
use App\Models\LeaveEncashment;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PerformanceCycle;
use App\Models\PerformanceTemplate;
use App\Models\Permission;
use App\Models\ProfileChangeRequest;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\EmployeeImportService;
use App\Services\Increments\IncrementService;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\PayrollService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Second review round of the compliance pass: regressions the first-round
 * guards introduced, and paths that still went around them.
 */
function r2User(UserRole $role, array $employee = [], float $basic = 26000): User
{
    $user = User::factory()->create(['role' => $role]);
    $staff = Employee::factory()->create(array_merge([
        'user_id' => $user->id, 'status' => 'active', 'salary_cycle' => 'cycle_a', 'manager_id' => null,
        // Joined before every run below: a run pays only people employed in its cycle.
        'joining_date' => '2024-01-08',
    ], $employee));

    $component = SalaryComponent::firstOrCreate(['code' => 'BASIC'], [
        'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
    ]);
    EmployeeSalary::create(['employee_id' => $staff->id, 'salary_component_id' => $component->id, 'amount' => $basic, 'effective_from' => '2025-07-01']);
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($staff->id)->toArray(), ['employee_id' => $staff->id]));

    return $user->fresh();
}

function r2Scorecard(Employee $employee, float $score, string $cycleEnd, User $actor): void
{
    $template = PerformanceTemplate::firstOrCreate(
        ['code' => 'R2-T'],
        ['name' => 'R2 Template', 'applies_to_type' => 'global', 'cycle_type' => 'quarterly', 'is_active' => true, 'created_by' => $actor->id],
    );
    $cycle = PerformanceCycle::create([
        'name' => 'Q ending '.$cycleEnd, 'template_id' => $template->id, 'cycle_type' => 'quarterly',
        'start_date' => Carbon::parse($cycleEnd)->subMonths(3)->toDateString(), 'end_date' => $cycleEnd,
        'status' => 'completed', 'created_by' => $actor->id,
    ]);
    EmployeeScorecard::create([
        'employee_id' => $employee->id, 'performance_cycle_id' => $cycle->id, 'template_id' => $template->id,
        'total_weighted_score' => $score, 'final_score' => $score, 'grade' => EmployeeScorecard::computeGrade($score), 'generated_at' => now(),
    ]);
}

beforeEach(function () {
    Notification::fake();
    Mail::fake();
    Storage::fake('local');
});

// ── Payroll ────────────────────────────────────────────────────────────────

test('a rejected run keeps its final settlement linked, so it is paid exactly once', function () {
    $hr = r2User(UserRole::HrAdmin);
    $finance = r2User(UserRole::Finance);
    $leaver = r2User(UserRole::Employee);
    ExitRecord::create(['employee_id' => $leaver->employee->id, 'last_working_day' => '2026-08-31', 'exit_type' => 'resignation', 'final_settlement_done' => true, 'final_settlement_amount' => 10000]);

    $service = app(PayrollService::class);
    $july = $service->generateDraft('July', 2026, 'cycle_a', $hr->id);
    $service->submitForFinanceApproval($july->fresh());
    $service->rejectFinance($july->fresh(), 'Recheck OT');

    // Still linked to July after the rejection.
    expect(ExitRecord::first()->payroll_id)->toBe($july->id);

    // Resubmitted without a re-run, then approved: paid in July.
    $service->submitForFinanceApproval($july->fresh());
    $service->approveFinance($july->fresh(), $finance->id);

    $august = $service->generateDraft('August', 2026, 'cycle_a', $hr->id);
    $augustNet = (float) Payslip::where('payroll_id', $august->id)->where('employee_id', $leaver->employee->id)->value('net_salary');

    expect($augustNet)->toBe(26000.0) // basic only — no second settlement
        ->and(ExitRecord::first()->payroll_id)->toBe($july->id);
});

test('an HR Admin sees "Waiting on Finance" instead of sign-off buttons they cannot use', function () {
    $hr = r2User(UserRole::HrAdmin);
    $finance = r2User(UserRole::Finance);
    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $finance->id);
    $service->submitForFinanceApproval($payroll->fresh());

    Livewire::actingAs($hr)->test(FinanceApproval::class)
        ->assertSee('Waiting on Finance')
        ->assertDontSee('Approve & Finalize', false);

    Livewire::actingAs($finance)->test(FinanceApproval::class)->assertSee('Approve & Finalize', false);
});

// ── Increments ─────────────────────────────────────────────────────────────

test('a Director approving a cycle holds only their own raise, and another approver releases it', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $director = r2User(UserRole::Director, [], 80000);
    $staff = r2User(UserRole::Employee, [], 50000);
    foreach ([$director->employee, $staff->employee] as $person) {
        r2Scorecard($person, 95, '2025-09-30', $superAdmin);
        r2Scorecard($person, 95, '2025-12-31', $superAdmin);
    }

    $service = app(IncrementService::class);
    $cycle = $service->openCycle('2026-27', '2026-07-01', 30, $superAdmin);
    $service->generateProposals($cycle, $superAdmin);
    $service->submitForApproval($cycle->fresh(), $superAdmin);

    $held = $service->approveCycle($cycle->fresh(), $director);
    $own = $cycle->proposals()->whereHas('employee', fn ($q) => $q->where('user_id', $director->id))->firstOrFail();
    $other = $cycle->proposals()->whereHas('employee', fn ($q) => $q->where('user_id', $staff->id))->firstOrFail();

    expect($held)->toBe(1)
        ->and($own->fresh()->status)->toBe('pending')
        ->and($other->fresh()->status)->toBe('approved')
        ->and($cycle->fresh()->status)->toBe('approved');

    expect(fn () => $service->approveHeldProposal($own->fresh(), $director))->toThrow(DomainException::class);

    $service->applyCycle($cycle->fresh(), $director);
    $service->approveHeldProposal($own->fresh(), $superAdmin);

    expect($own->fresh()->status)->toBe('approved')
        ->and(EmployeeSalary::where('employee_id', $director->employee->id)->whereNull('effective_to')->value('amount'))->not->toBe('80000.00');
});

// ── Encashment queue ───────────────────────────────────────────────────────

test('HR decides an encashment from the Approval Center and that decision is final (D3)', function () {
    $hr = r2User(UserRole::HrAdmin);
    $finance = r2User(UserRole::Finance);
    $employee = r2User(UserRole::Employee);
    $type = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true, 'allow_encashment' => true]);
    $encashment = LeaveEncashment::create(['employee_id' => $employee->employee->id, 'leave_type_id' => $type->id, 'requested_days' => 1, 'status' => 'pending']);

    // Finance does not get the first stage in its queue.
    Livewire::actingAs($finance)->test(ApprovalCenter::class)->assertDontSee($employee->name);

    Livewire::actingAs($hr)->test(ApprovalCenter::class)
        ->assertSee($employee->name)
        ->call('approve', 'encashment', $encashment->id);

    // D3: Director/HR approval is final — no Finance approval stage. Finance
    // processes the approved amount in payroll, not in an approval queue.
    expect($encashment->fresh()->status)->toBe('approved');

    Livewire::actingAs($finance)->test(ApprovalCenter::class)->assertDontSee($employee->name);
});

// ── Employee import ────────────────────────────────────────────────────────

test('an HR import cannot rewrite the Super Admin\'s login email, nor the importer\'s own record', function () {
    $hr = r2User(UserRole::HrAdmin);
    $superAdmin = r2User(UserRole::SuperAdmin);
    $row = fn (User $target, string $email) => [
        'line' => 2, 'status' => 'update', 'errors' => [],
        'data' => ['existing_user_id' => $target->id, 'email' => $email, 'name' => $target->name],
    ];

    $log = app(EmployeeImportService::class)->import(
        ['rows' => [$row($superAdmin, 'attacker@example.com'), $row($hr, 'me-again@example.com')], 'summary' => ['total' => 2]],
        'update', $hr,
    );

    expect($log->failed)->toBe(2)
        ->and($superAdmin->fresh()->email)->not->toBe('attacker@example.com')
        ->and($hr->fresh()->email)->not->toBe('me-again@example.com');
});

// ── HR profile page / employee edit on your own record ─────────────────────

test('HR cannot edit their own bank account on the HR profile page', function () {
    $hr = r2User(UserRole::HrAdmin);

    Livewire::actingAs($hr)->test(EmployeeProfile::class, ['employee' => $hr->employee])
        ->call('editField', 'account_number')
        ->assertForbidden();
});

test('HR cannot confirm their own probation', function () {
    $hr = r2User(UserRole::HrAdmin, ['status' => 'probation']);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $hr->employee])
        ->call('confirmProbation')
        ->assertForbidden();

    expect($hr->employee->fresh()->status->value)->toBe('probation');
});

test('a Director sees financial change-request values masked', function () {
    $director = r2User(UserRole::Director);
    $employee = r2User(UserRole::Employee);
    ProfileChangeRequest::create([
        'employee_id' => $employee->employee->id, 'requested_by' => $employee->id, 'field' => 'account_number',
        'old_value' => null, 'new_value' => '123456789012', 'status' => ProfileChangeRequest::STATUS_PENDING,
    ]);

    Livewire::actingAs($director)->test(EmployeeProfile::class, ['employee' => $employee->employee])
        ->call('setTab', 'requests')
        ->assertDontSee('123456789012')
        ->assertSee('•••• 12');
});

// ── Carry forward / offboarding / employee list ────────────────────────────

test('a bulk carry forward skips the HR user\'s own balance', function () {
    $hr = r2User(UserRole::HrAdmin);
    $type = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true]);
    $from = LeaveYear::firstOrCreate(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'], ['label' => '2025/26']);
    $to = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    $result = app(LeaveCarryForwardService::class)->applyDecisions($from, $to, [
        ['employee_id' => $hr->employee->id, 'leave_type_id' => $type->id, 'days' => 5],
    ], $hr, 'Year end');

    expect($result['applied'])->toBe(0)
        ->and(implode(' ', $result['errors']))->toContain('skipped');
});

test('a Director offboarding someone is not offered the payroll-only settlement fields', function () {
    $director = r2User(UserRole::Director);
    $employee = r2User(UserRole::Employee);

    Livewire::actingAs($director)->test(OffboardingManager::class)
        ->call('selectEmployee', $employee->employee->id)
        ->assertDontSee('Final Settlement Amount')
        ->assertSee('entered by payroll');
});

test('only holders of Permanently Delete Employees are offered the permanent-delete button', function () {
    $deleted = r2User(UserRole::Employee);
    $deleted->employee->delete();

    // Employee management without the purge permission: no button.
    $clerk = Role::create(['name' => 'Records Clerk', 'slug' => 'records-clerk', 'is_system' => false, 'is_active' => true]);
    $clerk->permissions()->sync(Permission::whereIn('key', ['manage_employees', 'delete_employee', 'view_employee'])->pluck('id'));
    $clerk->flushPermissionCache();
    $clerkUser = User::factory()->create(['role' => UserRole::Employee, 'role_id' => $clerk->id]);

    Livewire::actingAs($clerkUser)->test(EmployeeIndex::class)
        ->set('showDeleted', true)
        ->assertDontSee('Delete permanently');

    // HR Admin does not hold it by default: it stays with the Super Admin.
    Livewire::actingAs(r2User(UserRole::HrAdmin))->test(EmployeeIndex::class)
        ->set('showDeleted', true)
        ->assertDontSee('Delete permanently');

    Livewire::actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))->test(EmployeeIndex::class)
        ->set('showDeleted', true)
        ->assertSee('Delete permanently');
});
