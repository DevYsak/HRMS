<?php

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Support\Facades\URL;

function verifiablePayslip(): Payslip
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    $payroll = Payroll::create([
        'month' => 'January', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'finalized',
    ]);

    return Payslip::create([
        'payroll_id' => $payroll->id,
        'employee_id' => $employee->id,
        'gross_salary' => 125000,
        'total_deductions' => 10225,
        'net_salary' => 114775,
        'status' => 'paid',
    ]);
}

test('a signed QR link verifies the payslip without logging in', function () {
    $slip = verifiablePayslip();

    $this->get(URL::signedRoute('payroll.payslips.verify', ['payslip' => $slip->id]))
        ->assertOk()
        ->assertSee('Verified Authentic')
        ->assertSee($slip->employee->user->name)
        ->assertSee('January 2026')
        ->assertSee('1,14,775.00');
});

test('an unsigned verification URL is rejected', function () {
    $slip = verifiablePayslip();

    // No signature — guessing/enumerating payslip ids must not work.
    $this->get("/payslips/{$slip->id}/verify")->assertForbidden();
});

test('a tampered signature is rejected', function () {
    $slip = verifiablePayslip();

    $url = URL::signedRoute('payroll.payslips.verify', ['payslip' => $slip->id]);

    $this->get($url.'tampered')->assertForbidden();
});

test('the payslip pdf embeds a QR verification code', function () {
    $slip = verifiablePayslip();
    $this->actingAs($slip->employee->user);

    $html = view('pdf.payslip', ['payslip' => $slip->load(['payroll', 'items', 'employee.user'])])->render();

    expect($html)->toContain('data:image/svg+xml;base64,');
    expect($html)->toContain('Scan to verify this payslip');
});
