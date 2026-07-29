<?php

use App\Mail\PayslipMail;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Payroll;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Notifications\PayrollApprovalNotification;
use App\Notifications\PayslipGeneratedNotification;
use App\Notifications\SalaryStructureAssignedNotification;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 7: audits and fixes payroll notification dispatch. Found while
 * auditing: PayslipGeneratedNotification's custom toMail() independently
 * re-sent the same PayslipMail that dispatchFinalizedPayrollNotifications()
 * already sends directly — every finalized payroll double-emailed every
 * payslip. Also: submitForFinanceApproval() notified approvers with the
 * 'finance_approved' copy at submission time (before any approval), and
 * approveFinance()/rejectFinance() never notified the submitter at all.
 */
function notifiedEmployee(string $name): Employee
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'salary_cycle' => 'cycle_a']);

    $basic = SalaryComponent::firstOrCreate(
        ['code' => 'BASIC'],
        ['name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning', 'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1],
    );
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $basic->id, 'amount' => 30000]);
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($employee->id)->toArray(), ['employee_id' => $employee->id]));

    return $employee;
}

test('finalizing a payroll sends exactly one payslip email per employee, not two', function () {
    Mail::fake();
    $admin = User::factory()->create(['role' => 'super_admin']);
    notifiedEmployee('Asha');
    notifiedEmployee('Bob');

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('January', 2027, 'cycle_a', $admin->id);

    $service->dispatchFinalizedPayrollNotifications($payroll->fresh(['payslips.employee.user']));

    // PayslipMail implements ShouldQueue, so Mail::send() redirects it to the
    // queue rather than the immediate-send bucket — assert there, not assertSent.
    Mail::assertQueued(PayslipMail::class, 2);
});

test('the payslip-generated notification is dispatched to each employee on finalize', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'super_admin']);
    $asha = notifiedEmployee('Asha Notified');

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('February', 2027, 'cycle_a', $admin->id);
    $service->dispatchFinalizedPayrollNotifications($payroll->fresh(['payslips.employee.user']));

    Notification::assertSentTo($asha->user, PayslipGeneratedNotification::class);
});

test('submitting for finance approval notifies approvers with submitted copy, not approved copy', function () {
    Notification::fake();
    $maker = User::factory()->create(['role' => 'super_admin']);
    $finance = User::factory()->create(['role' => 'finance']);

    $payroll = Payroll::create(['month' => 'March', 'year' => 2027, 'cycle' => 'cycle_a', 'status' => 'draft', 'processed_by' => $maker->id]);
    app(PayrollService::class)->submitForFinanceApproval($payroll);

    Notification::assertSentTo($finance, PayrollApprovalNotification::class, function ($notification) {
        return $notification->event === 'submitted'
            && str_contains($notification->toArray($notification)['body'], 'submitted for finance approval');
    });
});

test('approving finance notifies the maker that their submission was approved', function () {
    Notification::fake();
    $maker = User::factory()->create(['role' => 'super_admin']);
    $approver = User::factory()->create(['role' => 'finance']);

    $payroll = Payroll::create(['month' => 'April', 'year' => 2027, 'cycle' => 'cycle_a', 'status' => 'pending_finance', 'processed_by' => $maker->id]);
    app(PayrollService::class)->approveFinance($payroll, $approver->id);

    Notification::assertSentTo($maker, PayrollApprovalNotification::class, fn ($n) => $n->event === 'finance_approved');
});

test('rejecting finance notifies the maker with the rejection reason', function () {
    Notification::fake();
    $maker = User::factory()->create(['role' => 'super_admin']);

    $payroll = Payroll::create(['month' => 'May', 'year' => 2027, 'cycle' => 'cycle_a', 'status' => 'pending_finance', 'processed_by' => $maker->id]);
    app(PayrollService::class)->rejectFinance($payroll, 'Overtime totals look wrong.');

    Notification::assertSentTo($maker, PayrollApprovalNotification::class, function ($n) {
        return $n->event === 'rejected' && str_contains($n->toArray($n)['body'], 'Overtime totals look wrong.');
    });
});

test('assigning a salary structure notifies the employee', function () {
    Notification::fake();
    $hrAdmin = User::factory()->create(['role' => 'hr_admin']);
    $employee = notifiedEmployee('Structure Assignee');

    $basic = SalaryComponent::create(['name' => 'New Basic', 'type' => 'earning', 'component_type' => 'earning', 'default_amount' => 20000]);
    $structure = SalaryStructure::create(['name' => 'Notify Test Structure', 'code' => 'NOTIFY_TEST', 'is_active' => true]);
    $structure->components()->sync([$basic->id => ['amount' => null]]);

    app(PayrollService::class)->assignSalaryStructure($structure, $employee, $hrAdmin, '2027-06-01');

    Notification::assertSentTo($employee->user, SalaryStructureAssignedNotification::class);
});
