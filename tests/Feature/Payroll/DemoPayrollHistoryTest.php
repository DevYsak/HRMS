<?php

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\User;
use Database\Seeders\DemoPayrollHistorySeeder;

/** Point the seeder at a freshly-made employee with a known employee_id. */
function demoTarget(string $employeeId = 'EMP-DEMO'): Employee
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id, 'employee_id' => $employeeId]);
    putenv("DEMO_PAYROLL_EMPLOYEE={$employeeId}");
    $_ENV['DEMO_PAYROLL_EMPLOYEE'] = $employeeId;

    return $employee;
}

afterEach(function () {
    putenv('DEMO_PAYROLL_EMPLOYEE');
    unset($_ENV['DEMO_PAYROLL_EMPLOYEE']);
});

test('the demo seeder creates six months of tagged payroll history', function () {
    $employee = demoTarget();

    (new DemoPayrollHistorySeeder)->run();

    expect(Payslip::where('employee_id', $employee->id)->count())->toBe(6);

    // Every payroll it made is tagged, so it can be found and removed later.
    $payrolls = Payroll::all();
    expect($payrolls)->toHaveCount(6);
    $payrolls->each(fn ($p) => expect($p->finance_note)->toBe(DemoPayrollHistorySeeder::DEMO_TAG));
});

test('demo payslips balance: gross minus deductions equals net', function () {
    $employee = demoTarget();

    (new DemoPayrollHistorySeeder)->run();

    Payslip::where('employee_id', $employee->id)->get()->each(function ($slip) {
        expect(round($slip->gross_salary - $slip->total_deductions, 2))
            ->toBe(round((float) $slip->net_salary, 2));
    });
});

test('demo payslip earnings add up to the gross', function () {
    $employee = demoTarget();

    (new DemoPayrollHistorySeeder)->run();

    $slip = Payslip::where('employee_id', $employee->id)->firstOrFail();
    $earnings = PayslipItem::where('payslip_id', $slip->id)->where('type', 'earning')->sum('amount');

    expect(round((float) $earnings, 2))->toBe(round((float) $slip->gross_salary, 2));
});

test('the demo seeder is idempotent and does not stack duplicate lines', function () {
    $employee = demoTarget();

    (new DemoPayrollHistorySeeder)->run();
    $firstItems = PayslipItem::count();

    (new DemoPayrollHistorySeeder)->run();

    expect(Payslip::where('employee_id', $employee->id)->count())->toBe(6);
    expect(Payroll::count())->toBe(6);
    expect(PayslipItem::count())->toBe($firstItems);
});

test('the demo seeder refuses to touch a real payroll for the same month', function () {
    $employee = demoTarget();

    // A genuine payroll run for the current month — no demo tag.
    $real = Payroll::create([
        'month' => now()->format('F'),
        'year' => now()->year,
        'cycle' => 'cycle_a',
        'status' => 'finalized',
        'finance_note' => 'Approved by finance.',
        'total_payout' => 12345,
    ]);

    (new DemoPayrollHistorySeeder)->run();

    $real->refresh();
    expect($real->finance_note)->toBe('Approved by finance.');
    expect((float) $real->total_payout)->toBe(12345.0);
    expect(Payslip::where('payroll_id', $real->id)->count())->toBe(0);

    // The other five months still seed.
    expect(Payslip::where('employee_id', $employee->id)->count())->toBe(5);
});

test('payroll demo-clear removes demo data but leaves real payroll alone', function () {
    demoTarget();

    $real = Payroll::create([
        'month' => 'December',
        'year' => 2019,
        'cycle' => 'cycle_a',
        'status' => 'finalized',
        'finance_note' => 'Genuine run.',
    ]);

    (new DemoPayrollHistorySeeder)->run();
    expect(Payroll::count())->toBe(7); // 6 demo + 1 real

    $this->artisan('payroll:demo-clear', ['--force' => true])->assertSuccessful();

    expect(Payroll::count())->toBe(1);
    expect(Payroll::first()->id)->toBe($real->id);
    expect(Payslip::count())->toBe(0);
    expect(PayslipItem::count())->toBe(0);
});

test('the seeder reports when no matching employee exists', function () {
    putenv('DEMO_PAYROLL_EMPLOYEE=NOBODY-9999');
    $_ENV['DEMO_PAYROLL_EMPLOYEE'] = 'NOBODY-9999';

    (new DemoPayrollHistorySeeder)->run();

    expect(Payroll::count())->toBe(0);
    expect(Payslip::count())->toBe(0);
});
