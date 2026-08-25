<?php

use App\Enums\UserRole;
use App\Livewire\Payroll\MyPayslips;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\User;
use Livewire\Livewire;

/**
 * Create a payslip for the given employee and month.
 *
 * payrolls is unique(month, year) — one run holds every employee's payslip for
 * that month — so the payroll is reused rather than recreated.
 */
function payslipFor(Employee $employee, string $month, int $year = 2026): Payslip
{
    $payroll = Payroll::firstOrCreate(
        ['month' => $month, 'year' => $year],
        ['cycle' => 'cycle_a', 'status' => 'finalized', 'total_payout' => 50000],
    );

    return Payslip::create([
        'payroll_id' => $payroll->id,
        'employee_id' => $employee->id,
        'gross_salary' => 50000,
        'total_deductions' => 5000,
        'net_salary' => 45000,
        'status' => 'paid',
    ]);
}

test('the single payslip pdf still renders after the shared-partial refactor', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $slip = payslipFor($employee, 'January');

    $response = $this->actingAs($user)
        ->get(route('payroll.payslips.download', $slip->id));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('an employee can print several months of payslips as one pdf', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    $ids = collect(['January', 'February', 'March'])
        ->map(fn ($m) => payslipFor($employee, $m)->id)
        ->all();

    $response = $this->actingAs($user)
        ->get(route('payroll.payslips.print-combined', ['ids' => $ids]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('the combined print refuses more than the six payslip cap', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    $ids = collect(['January', 'February', 'March', 'April', 'May', 'June', 'July'])
        ->map(fn ($m) => payslipFor($employee, $m)->id)
        ->all();

    expect($ids)->toHaveCount(7);

    $this->actingAs($user)
        ->get(route('payroll.payslips.print-combined', ['ids' => $ids]))
        ->assertSessionHasErrors('ids');
});

test('exactly six payslips is allowed', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    $ids = collect(['January', 'February', 'March', 'April', 'May', 'June'])
        ->map(fn ($m) => payslipFor($employee, $m)->id)
        ->all();

    $this->actingAs($user)
        ->get(route('payroll.payslips.print-combined', ['ids' => $ids]))
        ->assertOk()
        ->assertSessionHasNoErrors();
});

test('an employee cannot print another employee payslips', function () {
    $mine = User::factory()->create(['role' => UserRole::Employee]);
    $myEmployee = Employee::factory()->create(['user_id' => $mine->id]);

    $theirs = User::factory()->create(['role' => UserRole::Employee]);
    $theirEmployee = Employee::factory()->create(['user_id' => $theirs->id]);

    $mySlip = payslipFor($myEmployee, 'January');
    $theirSlip = payslipFor($theirEmployee, 'January');

    // Asking for both must silently drop the other employee's slip, not leak it.
    $response = $this->actingAs($mine)
        ->get(route('payroll.payslips.print-combined', ['ids' => [$mySlip->id, $theirSlip->id]]));

    $response->assertOk();

    // Only one payslip belongs to the requester, so only one may be rendered.
    $rendered = Payslip::whereIn('id', [$mySlip->id, $theirSlip->id])
        ->where('employee_id', $myEmployee->id)
        ->count();
    expect($rendered)->toBe(1);
});

test('requesting only another employee payslip is a 404, not a leak', function () {
    $mine = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $mine->id]);

    $theirs = User::factory()->create(['role' => UserRole::Employee]);
    $theirEmployee = Employee::factory()->create(['user_id' => $theirs->id]);
    $theirSlip = payslipFor($theirEmployee, 'January');

    $this->actingAs($mine)
        ->get(route('payroll.payslips.print-combined', ['ids' => [$theirSlip->id]]))
        ->assertNotFound();
});

test('payroll staff may print any employee payslips', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $hr->id]);

    $other = User::factory()->create(['role' => UserRole::Employee]);
    $otherEmployee = Employee::factory()->create(['user_id' => $other->id]);
    $slip = payslipFor($otherEmployee, 'January');

    $this->actingAs($hr)
        ->get(route('payroll.payslips.print-combined', ['ids' => [$slip->id]]))
        ->assertOk();
});

test('the payslip list exposes selection and a print action', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $slip = payslipFor($employee, 'January');

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->assertSeeHtml('wire:model.live="selected"')
        ->set('selected', [$slip->id])
        ->assertSeeHtml('wire:click="printSelected"')
        ->call('clearSelection')
        ->assertSet('selected', []);
});

test('printing with nothing selected does not redirect', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->set('selected', [])
        ->call('printSelected')
        ->assertNoRedirect();
});
