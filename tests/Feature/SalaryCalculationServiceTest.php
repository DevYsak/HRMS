<?php

use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Payroll;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\SalaryCalculationService;
use Illuminate\Support\Carbon;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function payrollEmployee(?array $attrs = []): Employee
{
    $user = User::factory()->create();

    return Employee::factory()->create(array_merge([
        'user_id' => $user->id,
        'status' => 'active',
        'salary_cycle' => 'cycle_a',
    ], $attrs));
}

function payrollComponent(array $overrides = []): SalaryComponent
{
    return SalaryComponent::create(array_merge([
        'name' => 'Component',
        'type' => 'earning',
        'is_fixed' => true,
        'default_amount' => 0,
        'is_active' => true,
        'calculation_type' => 'fixed',
    ], $overrides));
}

function assignSalary(Employee $employee, SalaryComponent $component, float $amount): EmployeeSalary
{
    return EmployeeSalary::create([
        'employee_id' => $employee->id,
        'salary_component_id' => $component->id,
        'amount' => $amount,
    ]);
}

function draftPayroll(): Payroll
{
    return Payroll::create([
        'month' => 'June',
        'year' => 2026,
        'cycle' => 'cycle_a',
        'status' => 'draft',
    ]);
}

// ─── Tests ────────────────────────────────────────────────────────────────────

test('HRA is calculated from per-employee percentage when enabled', function () {
    $employee = payrollEmployee();

    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $hra = payrollComponent(['name' => 'HRA', 'code' => 'HRA', 'component_type' => 'earning', 'calculation_type' => 'percentage', 'percentage_basis' => 'basic', 'percentage_value' => 10, 'display_order' => 2]);

    assignSalary($employee, $basic, 30000);
    assignSalary($employee, $hra, 0);

    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id, 'hra_enabled' => true, 'hra_percentage' => 50],
    ));

    $payroll = draftPayroll();
    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', $payroll);

    $hraItem = collect($result->earningItems)->firstWhere('name', 'HRA');

    expect($hraItem['amount'])->toBe(15000.0)
        ->and($result->gross)->toBe(45000.0)
        ->and($result->net)->toBe(45000.0);
});

test('HRA is forced to zero when disabled for the employee', function () {
    $employee = payrollEmployee();

    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $hra = payrollComponent(['name' => 'HRA', 'code' => 'HRA', 'component_type' => 'earning', 'calculation_type' => 'percentage', 'percentage_basis' => 'basic', 'percentage_value' => 50, 'display_order' => 2]);

    assignSalary($employee, $basic, 25000);
    assignSalary($employee, $hra, 0);

    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id, 'hra_enabled' => false],
    ));

    $payroll = draftPayroll();
    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', $payroll);

    $hraItem = collect($result->earningItems)->firstWhere('name', 'HRA');

    expect($hraItem['amount'])->toBe(0.0)
        ->and($result->gross)->toBe(25000.0);
});

test('PF deduction is zeroed out when PF is disabled for the employee', function () {
    $employee = payrollEmployee();

    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $pf = payrollComponent(['name' => 'PF', 'type' => 'deduction', 'code' => 'PF', 'component_type' => 'deduction', 'is_pf_applicable' => true, 'display_order' => 1]);

    assignSalary($employee, $basic, 30000);
    assignSalary($employee, $pf, 1800);

    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id, 'pf_enabled' => false],
    ));

    $payroll = draftPayroll();
    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', $payroll);

    $pfItem = collect($result->deductionItems)->firstWhere('name', 'PF');

    expect($pfItem['amount'])->toBe(0.0)
        ->and($result->totalDeductions)->toBe(0.0)
        ->and($result->net)->toBe(30000.0);
});

test('PF deduction is applied when PF is enabled for the employee', function () {
    $employee = payrollEmployee();

    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $pf = payrollComponent(['name' => 'PF', 'type' => 'deduction', 'code' => 'PF', 'component_type' => 'deduction', 'is_pf_applicable' => true, 'display_order' => 1]);

    assignSalary($employee, $basic, 30000);
    assignSalary($employee, $pf, 1800);

    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id, 'pf_enabled' => true],
    ));

    $payroll = draftPayroll();
    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', $payroll);

    $pfItem = collect($result->deductionItems)->firstWhere('name', 'PF');

    expect($pfItem['amount'])->toBe(1800.0)
        ->and($result->totalDeductions)->toBe(1800.0)
        ->and($result->net)->toBe(28200.0);
});

test('legacy employees with no payroll settings row behave like the old fixed engine', function () {
    $employee = payrollEmployee();

    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    assignSalary($employee, $basic, 50000);

    $payroll = draftPayroll();
    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', $payroll);

    expect($result->gross)->toBe(50000.0)
        ->and($result->totalDeductions)->toBe(0.0)
        ->and($result->net)->toBe(50000.0);
});

// ─── Statutory computation (Indian payroll) ───────────────────────────────────

function enableSettings(Employee $employee, array $flags): void
{
    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        array_merge(['employee_id' => $employee->id], $flags),
    ));
}

test('PF is 12% of actual basic when basic is below the 15k ceiling', function () {
    $employee = payrollEmployee();
    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $pf = payrollComponent(['name' => 'Provident Fund (PF)', 'type' => 'deduction', 'code' => 'PF', 'component_type' => 'deduction', 'is_pf_applicable' => true, 'display_order' => 1]);

    assignSalary($employee, $basic, 12000);
    assignSalary($employee, $pf, 1800); // configured amount ignored — statutory recomputes
    enableSettings($employee, ['pf_enabled' => true]);

    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', draftPayroll());

    expect(collect($result->deductionItems)->firstWhere('name', 'Provident Fund (PF)')['amount'])->toBe(1440.0)
        ->and($result->net)->toBe(10560.0);
});

test('ESI is deducted at 0.75% only within the 21k gross ceiling', function () {
    $employee = payrollEmployee();
    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $esi = payrollComponent(['name' => 'ESI', 'type' => 'deduction', 'code' => 'ESI', 'component_type' => 'deduction', 'is_esi_applicable' => true, 'display_order' => 1]);

    assignSalary($employee, $basic, 18000);
    assignSalary($employee, $esi, 0);
    enableSettings($employee, ['esi_enabled' => true]);

    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', draftPayroll());

    expect(collect($result->deductionItems)->firstWhere('name', 'ESI')['amount'])->toBe(135.0); // ceil(18000 * 0.75%)
});

test('ESI is skipped when gross exceeds the 21k ceiling', function () {
    $employee = payrollEmployee();
    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $esi = payrollComponent(['name' => 'ESI', 'type' => 'deduction', 'code' => 'ESI', 'component_type' => 'deduction', 'is_esi_applicable' => true, 'display_order' => 1]);

    assignSalary($employee, $basic, 30000);
    assignSalary($employee, $esi, 0);
    enableSettings($employee, ['esi_enabled' => true]);

    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', draftPayroll());

    expect(collect($result->deductionItems)->firstWhere('name', 'ESI')['amount'])->toBe(0.0);
});

test('Maharashtra professional tax deducts 200 in a normal month', function () {
    $employee = payrollEmployee(['gender' => 'male']);
    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $pt = payrollComponent(['name' => 'Professional Tax', 'type' => 'deduction', 'code' => 'PROFESSIONAL_TAX', 'component_type' => 'deduction', 'display_order' => 2]);

    assignSalary($employee, $basic, 30000);
    assignSalary($employee, $pt, 0);
    enableSettings($employee, ['professional_tax_enabled' => true]);

    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', draftPayroll());

    expect(collect($result->deductionItems)->firstWhere('name', 'Professional Tax')['amount'])->toBe(200.0);
});

test('TDS is deducted for a high earner under the new regime', function () {
    $employee = payrollEmployee(['gender' => 'male']);
    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $tds = payrollComponent(['name' => 'Income Tax (TDS)', 'type' => 'deduction', 'code' => 'TDS', 'component_type' => 'deduction', 'display_order' => 3]);

    assignSalary($employee, $basic, 200000); // 24L annual projection
    assignSalary($employee, $tds, 0);
    enableSettings($employee, ['tds_enabled' => true]);

    $result = app(SalaryCalculationService::class)->calculate($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '2026-06', draftPayroll());

    expect(collect($result->deductionItems)->firstWhere('name', 'Income Tax (TDS)')['amount'])->toBe(24375.0);
});

test('PayrollService generateDraft produces payslips using the dynamic engine', function () {
    $employee = payrollEmployee();
    $processor = User::factory()->create();

    $basic = payrollComponent(['name' => 'Basic Salary', 'code' => 'BASIC', 'component_type' => 'earning', 'display_order' => 1]);
    $special = payrollComponent(['name' => 'Special Allowance', 'code' => 'SPECIAL', 'component_type' => 'earning', 'display_order' => 2]);
    $pf = payrollComponent(['name' => 'Provident Fund', 'type' => 'deduction', 'code' => 'PF', 'component_type' => 'deduction', 'display_order' => 1]);

    assignSalary($employee, $basic, 50000);
    assignSalary($employee, $special, 10000);
    assignSalary($employee, $pf, 1800);

    $payroll = app(PayrollService::class)->generateDraft('June', 2026, 'cycle_a', $processor->id);

    expect($payroll->status)->toBe('draft')
        ->and($payroll->payslips)->toHaveCount(1);

    $payslip = $payroll->payslips->first();

    expect((float) $payslip->gross_salary)->toBe(60000.0)
        ->and((float) $payslip->total_deductions)->toBe(1800.0)
        ->and((float) $payslip->net_salary)->toBe(58200.0)
        ->and($payslip->items)->toHaveCount(3);
});
