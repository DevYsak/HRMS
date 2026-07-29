<?php

use App\Livewire\Payroll\Process;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\PayrollService;
use Livewire\Livewire;

function processActionsEmployee(string $name, float $basicAmount): Employee
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'salary_cycle' => 'cycle_a',
    ]);

    $basic = SalaryComponent::firstOrCreate(
        ['code' => 'BASIC'],
        [
            'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
            'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
        ],
    );

    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $basic->id, 'amount' => $basicAmount]);
    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id],
    ));

    return $employee;
}

test('a user without delete_payslip permission is forbidden from deleting via the Process screen', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $finance = User::factory()->create(['role' => 'finance']); // run_payroll yes, delete_payslip no
    $alice = processActionsEmployee('Alice', 40000);

    $payroll = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->first();

    Livewire::actingAs($finance)->test(Process::class)
        ->call('deleteSingle', $slip->id)
        ->assertForbidden();

    expect(Payslip::find($slip->id))->not->toBeNull();
});

test('selectAllVisible fills selected with every payslip on the current page', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    processActionsEmployee('Alice', 40000);
    processActionsEmployee('Bob', 25000);

    app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id);

    Livewire::actingAs($admin)->test(Process::class)
        ->call('selectAllVisible')
        ->assertCount('selected', 2);
});

test('opening the edit modal preloads the payslip\'s current line items', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    processActionsEmployee('Alice', 40000);

    $payroll = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->first();

    Livewire::actingAs($admin)->test(Process::class)
        ->call('openEdit', $slip->id)
        ->assertSet('editingId', $slip->id)
        ->assertSet('editItems.0.name', 'Basic Salary')
        ->assertSet('editItems.0.amount', 40000.0);
});

test('saving an edit persists the new items and closes the modal state', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    processActionsEmployee('Alice', 40000);

    $payroll = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->first();

    Livewire::actingAs($admin)->test(Process::class)
        ->call('openEdit', $slip->id)
        ->set('editItems.0.amount', 45000)
        ->call('saveEdit')
        ->assertSet('editingId', null);

    expect((float) $slip->fresh()->gross_salary)->toBe(45000.0);
});

test('bulk download with nothing selected shows a toast instead of redirecting', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    processActionsEmployee('Alice', 40000);
    app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id);

    Livewire::actingAs($admin)->test(Process::class)
        ->call('bulkDownload')
        ->assertNoRedirect();
});

test('bulk download with a selection redirects to the bulk zip route', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    processActionsEmployee('Alice', 40000);
    $payroll = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->first();

    Livewire::actingAs($admin)->test(Process::class)
        ->set('selected', [$slip->id])
        ->call('bulkDownload')
        ->assertRedirect(route('payroll.payslips.download-bulk', ['ids' => [$slip->id]]));
});
