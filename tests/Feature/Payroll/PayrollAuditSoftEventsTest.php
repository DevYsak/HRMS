<?php

use App\Enums\UserRole;
use App\Livewire\Payroll\MyPayslips;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Observers only ever caught created/updated/deleted — a payslip being
 * viewed/downloaded/emailed is a read, not a model mutation, so it never left
 * a trace anywhere. These are the two soft events this phase adds explicit
 * AuditLog::record() calls for.
 */
function auditedPayslipFor(Employee $employee, string $month, int $year = 2026): Payslip
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

test('downloading a payslip records a downloaded audit entry against the employee', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $slip = auditedPayslipFor($employee, 'January');

    $this->actingAs($user)->get(route('payroll.payslips.download', $slip->id))->assertOk();

    $entry = AuditLog::where('auditable_type', Payslip::class)
        ->where('auditable_id', $slip->id)
        ->where('action', 'downloaded')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->subject_employee_id)->toBe($employee->id)
        ->and($entry->user_id)->toBe($user->id);
});

test('emailing a payslip to yourself records an emailed audit entry', function () {
    Mail::fake();

    $user = User::factory()->create(['role' => UserRole::Employee, 'email' => 'yogesh@example.test']);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $slip = auditedPayslipFor($employee, 'February');

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->call('emailPayslip', $slip->id);

    $entry = AuditLog::where('auditable_type', Payslip::class)
        ->where('auditable_id', $slip->id)
        ->where('action', 'emailed')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->subject_employee_id)->toBe($employee->id)
        ->and($entry->new_values['to'])->toBe('yogesh@example.test');
});
