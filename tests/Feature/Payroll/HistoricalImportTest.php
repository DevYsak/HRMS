<?php

use App\Livewire\Payroll\HistoricalImport;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollImportLog;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\User;
use App\Services\PayrollHistoricalImportService;
use Livewire\Livewire;

/**
 * Phase 5: bulk-import pre-Pulse payslip history. Figures come straight from
 * the file (nothing is recalculated) and rows are written as already-paid,
 * finalized history — mirroring DemoPayrollHistorySeeder's "never touch a
 * real payroll it did not create" safety rule.
 */
function historicalEmployee(string $employeeId, string $email): Employee
{
    $user = User::factory()->create(['email' => $email]);

    return Employee::factory()->create(['user_id' => $user->id, 'employee_id' => $employeeId, 'status' => 'active']);
}

test('parse matches by employee id, by email, and flags an unmatched row', function () {
    $byId = historicalEmployee('EMP-9001', 'byid@example.com');
    $byEmail = historicalEmployee('EMP-9002', 'byemail@example.com');

    $result = app(PayrollHistoricalImportService::class)->parse([
        ['employee_id' => 'EMP-9001', 'month' => 'January', 'year' => '2026', 'gross_salary' => '30000', 'net_salary' => '28000'],
        ['email' => 'byemail@example.com', 'month' => 'January', 'year' => '2026', 'gross_salary' => '30000', 'net_salary' => '28000'],
        ['employee_id' => 'NOBODY', 'month' => 'January', 'year' => '2026', 'gross_salary' => '30000'],
    ]);

    expect(array_column($result['rows'], 'status'))->toBe(['new', 'new', 'error']);
    expect($result['rows'][0]['data']['employee_id'])->toBe($byId->id);
    expect($result['rows'][1]['data']['employee_id'])->toBe($byEmail->id);
    expect(implode(' ', $result['rows'][2]['errors']))->toContain('No matching employee found');
});

test('parse rejects an unrecognised month and out-of-range year', function () {
    $employee = historicalEmployee('EMP-9010', 'badmonth@example.com');

    $result = app(PayrollHistoricalImportService::class)->parse([
        ['employee_id' => 'EMP-9010', 'month' => 'Smarch', 'year' => '2026', 'gross_salary' => '30000'],
        ['employee_id' => 'EMP-9010', 'month' => 'January', 'year' => '99', 'gross_salary' => '30000'],
    ]);

    expect($result['rows'][0]['status'])->toBe('error')
        ->and(implode(' ', $result['rows'][0]['errors']))->toContain('not recognised');
    expect($result['rows'][1]['status'])->toBe('error')
        ->and(implode(' ', $result['rows'][1]['errors']))->toContain('valid 4-digit year');
});

test('parse computes gross and net from named line items when totals are blank', function () {
    $employee = historicalEmployee('EMP-9020', 'lineitems@example.com');

    $result = app(PayrollHistoricalImportService::class)->parse([
        ['employee_id' => 'EMP-9020', 'month' => 'February', 'year' => '2026', 'basic' => '20000', 'hra' => '10000', 'pf' => '2400'],
    ]);

    $data = $result['rows'][0]['data'];
    expect($data['gross_salary'])->toBe(30000.0)
        ->and($data['total_deductions'])->toBe(2400.0)
        ->and($data['net_salary'])->toBe(27600.0);
});

test('parse flags a duplicate when the employee already has a payslip for that month and year', function () {
    $employee = historicalEmployee('EMP-9030', 'dup@example.com');
    $payroll = Payroll::create(['month' => 'March', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'finalized']);
    Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $employee->id, 'gross_salary' => 1, 'total_deductions' => 0, 'net_salary' => 1, 'status' => 'paid']);

    $result = app(PayrollHistoricalImportService::class)->parse([
        ['employee_id' => 'EMP-9030', 'month' => 'March', 'year' => '2026', 'gross_salary' => '30000'],
    ]);

    expect($result['rows'][0]['status'])->toBe('duplicate');
    expect($result['summary']['duplicate'])->toBe(1);
});

test('parse flags the second of two rows in the same file for the same employee/month/year as a duplicate', function () {
    historicalEmployee('EMP-9040', 'samefile@example.com');

    $result = app(PayrollHistoricalImportService::class)->parse([
        ['employee_id' => 'EMP-9040', 'month' => 'April', 'year' => '2026', 'gross_salary' => '30000'],
        ['employee_id' => 'EMP-9040', 'month' => 'April', 'year' => '2026', 'gross_salary' => '30000'],
    ]);

    expect(array_column($result['rows'], 'status'))->toBe(['new', 'duplicate']);
});

test('import creates a finalized payroll, a paid payslip, and item breakdown from new rows', function () {
    $admin = User::factory()->create(['role' => 'hr_admin']);
    $employee = historicalEmployee('EMP-9050', 'import1@example.com');

    $service = app(PayrollHistoricalImportService::class);
    $parsed = $service->parse([
        ['employee_id' => 'EMP-9050', 'month' => 'May', 'year' => '2026', 'basic' => '20000', 'hra' => '8000', 'pf' => '2400'],
    ]);

    $log = $service->import($parsed, $admin, 'history.xlsx');

    expect($log->imported)->toBe(1)
        ->and($log->skipped)->toBe(0)
        ->and($log->failed)->toBe(0);

    $payroll = Payroll::where('month', 'May')->where('year', 2026)->where('cycle', 'cycle_a')->first();
    expect($payroll)->not->toBeNull()
        ->and($payroll->status)->toBe('finalized')
        ->and($payroll->finance_note)->toBe(PayrollHistoricalImportService::IMPORT_TAG);

    $payslip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $employee->id)->first();
    expect($payslip)->not->toBeNull()
        ->and($payslip->status)->toBe('paid')
        ->and((float) $payslip->gross_salary)->toBe(28000.0)
        ->and((float) $payslip->total_deductions)->toBe(2400.0);

    expect(PayslipItem::where('payslip_id', $payslip->id)->count())->toBe(3);
    expect((float) $payroll->fresh()->total_payout)->toBe((float) $payslip->net_salary);
});

test('import refuses to add a historical payslip into a real payroll for the same period', function () {
    $admin = User::factory()->create(['role' => 'hr_admin']);
    $employee = historicalEmployee('EMP-9060', 'realpayroll@example.com');

    // A real payroll run — not tagged as import-owned.
    Payroll::create(['month' => 'June', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'finalized', 'processed_by' => $admin->id]);

    $service = app(PayrollHistoricalImportService::class);
    $parsed = $service->parse([
        ['employee_id' => 'EMP-9060', 'month' => 'June', 'year' => '2026', 'gross_salary' => '30000'],
    ]);

    $log = $service->import($parsed, $admin, 'conflict.xlsx');

    expect($log->imported)->toBe(0)
        ->and($log->failed)->toBe(1);
    expect(Payslip::where('employee_id', $employee->id)->exists())->toBeFalse();
});

test('a second import for the same period reuses the import-owned payroll instead of creating a duplicate', function () {
    $admin = User::factory()->create(['role' => 'hr_admin']);
    historicalEmployee('EMP-9070', 'first@example.com');
    historicalEmployee('EMP-9071', 'second@example.com');

    $service = app(PayrollHistoricalImportService::class);

    $service->import($service->parse([
        ['employee_id' => 'EMP-9070', 'month' => 'July', 'year' => '2026', 'gross_salary' => '30000'],
    ]), $admin);

    $service->import($service->parse([
        ['employee_id' => 'EMP-9071', 'month' => 'July', 'year' => '2026', 'gross_salary' => '25000'],
    ]), $admin);

    expect(Payroll::where('month', 'July')->where('year', 2026)->where('cycle', 'cycle_a')->count())->toBe(1);

    $payroll = Payroll::where('month', 'July')->where('year', 2026)->first();
    expect($payroll->payslips)->toHaveCount(2)
        ->and((float) $payroll->fresh()->total_payout)->toBe(55000.0);
});

test('the historical import screen wires analyze results through to a real import', function () {
    $admin = User::factory()->create(['role' => 'hr_admin']);
    historicalEmployee('EMP-9080', 'livewire@example.com');

    $parsed = app(PayrollHistoricalImportService::class)->parse([
        ['employee_id' => 'EMP-9080', 'month' => 'August', 'year' => '2026', 'gross_salary' => '30000'],
    ]);

    Livewire::actingAs($admin)
        ->test(HistoricalImport::class)
        ->set('parsed', $parsed)
        ->set('showPreview', true)
        ->call('runImport')
        ->assertSet('showPreview', false);

    expect(PayrollImportLog::count())->toBe(1);
    expect(Payslip::whereHas('employee', fn ($q) => $q->where('employee_id', 'EMP-9080'))->exists())->toBeTrue();
});
