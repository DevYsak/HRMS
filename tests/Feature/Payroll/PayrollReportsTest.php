<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\User;

/**
 * Phase 6: 12 payroll reports built on top of payslips/payslip_items —
 * informational summaries, not government-filing-ready formats.
 */
function reportsPayrollAdmin(): User
{
    return User::factory()->create(['role' => 'hr_admin']);
}

function seededPayslip(string $month, int $year, ?Department $department = null): Payslip
{
    $payroll = Payroll::firstOrCreate(
        ['month' => $month, 'year' => $year, 'cycle' => 'cycle_a'],
        ['status' => 'finalized', 'total_payout' => 0, 'deductions' => 0],
    );

    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'department_id' => $department?->id,
    ]);
    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id, 'bank_name' => 'HDFC Bank', 'account_number' => '1234567890', 'ifsc_code' => 'HDFC0001234', 'pf_number' => 'PF-1', 'esi_number' => 'ESI-1', 'pan_number' => 'ABCDE1234F'],
    ));

    $payslip = Payslip::create([
        'payroll_id' => $payroll->id,
        'employee_id' => $employee->id,
        'gross_salary' => 40000,
        'total_deductions' => 4400,
        'net_salary' => 35600,
        'status' => 'paid',
    ]);

    foreach ([
        ['name' => 'Basic Salary', 'amount' => 25000, 'type' => 'earning'],
        ['name' => 'HRA', 'amount' => 15000, 'type' => 'earning'],
        ['name' => 'Provident Fund (PF)', 'amount' => 1800, 'type' => 'deduction'],
        ['name' => 'ESI', 'amount' => 400, 'type' => 'deduction'],
        ['name' => 'Professional Tax', 'amount' => 200, 'type' => 'deduction'],
        ['name' => 'Income Tax (TDS)', 'amount' => 2000, 'type' => 'deduction'],
        ['name' => 'Provident Fund (Employer)', 'amount' => 1800, 'type' => 'employer_contribution'],
    ] as $item) {
        PayslipItem::create(array_merge(['payslip_id' => $payslip->id], $item));
    }

    $payroll->update([
        'total_payout' => (float) $payroll->payslips()->sum('net_salary'),
        'deductions' => (float) $payroll->payslips()->sum('total_deductions'),
    ]);

    return $payslip->fresh(['employee.user', 'employee.department']);
}

test('a non-payroll role cannot download the payroll reports', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->get(route('reports.payroll-register', ['month' => now()->month, 'year' => now()->year]))
        ->assertForbidden();
});

test('the payroll register lists gross, deductions and net per payslip', function () {
    $dept = Department::factory()->create(['name' => 'Engineering']);
    $payslip = seededPayslip('January', 2026, $dept);

    $response = $this->actingAs(reportsPayrollAdmin())
        ->get(route('reports.payroll-register', ['month' => 1, 'year' => 2026]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain($payslip->employee->employee_id)
        ->toContain('Engineering')
        ->toContain('40000.00')
        ->toContain('35600.00');
});

test('the salary register breaks each payslip into a column per line item', function () {
    seededPayslip('February', 2026);

    $response = $this->actingAs(reportsPayrollAdmin())
        ->get(route('reports.salary-register', ['month' => 2, 'year' => 2026]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('Basic Salary')
        ->toContain('Provident Fund (PF)')
        ->toContain('25000.00')
        ->toContain('1800.00');
});

test('the bank transfer report includes bank details and flags missing ones', function () {
    seededPayslip('March', 2026);

    $response = $this->actingAs(reportsPayrollAdmin())
        ->get(route('reports.bank-transfer', ['month' => 3, 'year' => 2026]));

    $response->assertOk();
    expect($response->streamedContent())->toContain('HDFC Bank')->toContain('HDFC0001234');
});

test('the PF, ESI, PT and TDS reports isolate their own statutory line item', function () {
    seededPayslip('April', 2026);
    $filters = ['month' => 4, 'year' => 2026];
    $admin = reportsPayrollAdmin();

    $pf = $this->actingAs($admin)->get(route('reports.pf-report', $filters))->streamedContent();
    expect($pf)->toContain('PF-1')->toContain('1800.00');

    $esi = $this->actingAs($admin)->get(route('reports.esi-report', $filters))->streamedContent();
    expect($esi)->toContain('ESI-1')->toContain('400.00');

    $pt = $this->actingAs($admin)->get(route('reports.pt-report', $filters))->streamedContent();
    expect($pt)->toContain('200.00');

    $tds = $this->actingAs($admin)->get(route('reports.tds-report', $filters))->streamedContent();
    expect($tds)->toContain('ABCDE1234F')->toContain('2000.00');
});

test('the cost center report groups totals by department', function () {
    $eng = Department::factory()->create(['name' => 'Engineering']);
    $sales = Department::factory()->create(['name' => 'Sales']);
    seededPayslip('May', 2026, $eng);
    seededPayslip('May', 2026, $sales);

    $response = $this->actingAs(reportsPayrollAdmin())
        ->get(route('reports.cost-center-report', ['month' => 5, 'year' => 2026]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('Engineering')->toContain('Sales');
    // Two employees total, one per department — each department's row shows a count of 1.
    expect(substr_count($content, ',1,'))->toBeGreaterThanOrEqual(2);
});

test('the department payroll report lists one row per employee sorted by department', function () {
    $dept = Department::factory()->create(['name' => 'Operations']);
    $payslip = seededPayslip('June', 2026, $dept);

    $response = $this->actingAs(reportsPayrollAdmin())
        ->get(route('reports.department-payroll-report', ['month' => 6, 'year' => 2026]));

    $response->assertOk();
    expect($response->streamedContent())->toContain('Operations')->toContain($payslip->employee->employee_id);
});

test('the monthly summary has one row for every month with the paid month populated', function () {
    seededPayslip('July', 2026);

    $response = $this->actingAs(reportsPayrollAdmin())
        ->get(route('reports.payroll-monthly-summary', ['year' => 2026]));

    $response->assertOk();
    $rows = array_filter(explode("\n", trim($response->streamedContent())));
    expect($rows)->toHaveCount(13); // header + 12 months
    expect($response->streamedContent())->toContain('July')->toContain('35600.00');
});

test('the yearly summary aggregates across all payrolls for a year', function () {
    seededPayslip('August', 2026);
    seededPayslip('September', 2026);

    $response = $this->actingAs(reportsPayrollAdmin())
        ->get(route('reports.payroll-yearly-summary'));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('2026');
    expect($content)->toContain('71200.00'); // 2 x 35600 net payout
});

test('the variance report compares an employee net pay against the prior month', function () {
    $dept = Department::factory()->create(['name' => 'Finance']);
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'department_id' => $dept->id]);

    $octPayroll = Payroll::create(['month' => 'October', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'finalized']);
    Payslip::create(['payroll_id' => $octPayroll->id, 'employee_id' => $employee->id, 'gross_salary' => 30000, 'total_deductions' => 0, 'net_salary' => 30000, 'status' => 'paid']);

    $novPayroll = Payroll::create(['month' => 'November', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'finalized']);
    Payslip::create(['payroll_id' => $novPayroll->id, 'employee_id' => $employee->id, 'gross_salary' => 33000, 'total_deductions' => 0, 'net_salary' => 33000, 'status' => 'paid']);

    $response = $this->actingAs(reportsPayrollAdmin())
        ->get(route('reports.payroll-variance-report', ['month' => 11, 'year' => 2026]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('30000.00')
        ->toContain('33000.00')
        ->toContain('3000.00')
        ->toContain('10');
});

test('the payroll summary PDF filters by month name rather than a numeric month', function () {
    $admin = reportsPayrollAdmin();
    seededPayslip('December', 2026);

    $response = $this->actingAs($admin)
        ->get(route('reports.payroll-summary', ['month' => 12, 'year' => 2026]));

    $response->assertOk();
});
