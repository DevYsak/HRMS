<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeCreate;
use App\Models\EmployeePayrollSettings;
use App\Models\SalaryStructure;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('employee create form succeeds when payroll information is left blank', function () {
    Mail::fake();
    Notification::fake();

    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hrAdmin)
        ->test(EmployeeCreate::class)
        ->set('name', 'Jane Blank')
        ->set('email', 'jane.blank@conexus-ns.com')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'jane.blank@conexus-ns.com')->first();
    $settings = EmployeePayrollSettings::where('employee_id', $user->employee->id)->first();

    expect($settings)->not->toBeNull()
        ->and($settings->ctc)->toBeNull()
        ->and($settings->salary_structure_id)->toBeNull()
        ->and($settings->pf_enabled)->toBeFalse()
        ->and($settings->esi_enabled)->toBeFalse()
        ->and($settings->ot_eligible)->toBeFalse()
        ->and($settings->bank_name)->toBeNull();
});

test('employee create form saves payroll information for the new employee', function () {
    Mail::fake();
    Notification::fake();

    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $structure = SalaryStructure::create(['name' => 'Standard Structure', 'is_active' => true]);

    Livewire::actingAs($hrAdmin)
        ->test(EmployeeCreate::class)
        ->set('name', 'Jane Payroll')
        ->set('email', 'jane.payroll@conexus-ns.com')
        ->set('ctc', 600000)
        ->set('salary_structure_id', (string) $structure->id)
        ->set('pf_enabled', true)
        ->set('pf_number', 'MH/BAN/12345/123')
        ->set('uan_number', '100200300400')
        ->set('esi_enabled', true)
        ->set('esi_number', '31000000000000000')
        ->set('professional_tax_enabled', true)
        ->set('ot_eligible', true)
        ->set('ot_rate_per_hour', 150)
        ->set('incentive_eligible', true)
        ->set('reimbursement_eligible', true)
        ->set('bank_name', 'HDFC Bank')
        ->set('account_number', '50100000000000')
        ->set('ifsc_code', 'HDFC0001234')
        ->set('pan_number', 'ABCDE1234F')
        ->set('aadhar_number', '123456789012')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'jane.payroll@conexus-ns.com')->first();
    $settings = EmployeePayrollSettings::where('employee_id', $user->employee->id)->first();

    expect($settings)->not->toBeNull()
        ->and((float) $settings->ctc)->toBe(600000.0)
        ->and($settings->salary_structure_id)->toBe($structure->id)
        ->and($settings->pf_enabled)->toBeTrue()
        ->and($settings->pf_number)->toBe('MH/BAN/12345/123')
        ->and($settings->uan_number)->toBe('100200300400')
        ->and($settings->esi_enabled)->toBeTrue()
        ->and($settings->esi_number)->toBe('31000000000000000')
        ->and($settings->professional_tax_enabled)->toBeTrue()
        ->and($settings->ot_eligible)->toBeTrue()
        ->and((float) $settings->ot_rate_per_hour)->toBe(150.0)
        ->and($settings->incentive_eligible)->toBeTrue()
        ->and($settings->reimbursement_eligible)->toBeTrue()
        ->and($settings->bank_name)->toBe('HDFC Bank')
        ->and($settings->account_number)->toBe('50100000000000')
        ->and($settings->ifsc_code)->toBe('HDFC0001234')
        ->and($settings->pan_number)->toBe('ABCDE1234F')
        ->and($settings->aadhar_number)->toBe('123456789012');
});

test('pf number and uan number are required when pf applicable is enabled', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hrAdmin)
        ->test(EmployeeCreate::class)
        ->set('name', 'Jane Payroll')
        ->set('email', 'jane.pf@conexus-ns.com')
        ->set('pf_enabled', true)
        ->call('save')
        ->assertHasErrors(['pf_number' => 'required_if', 'uan_number' => 'required_if']);
});

test('esi number is required when esi applicable is enabled', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hrAdmin)
        ->test(EmployeeCreate::class)
        ->set('name', 'Jane Payroll')
        ->set('email', 'jane.esi@conexus-ns.com')
        ->set('esi_enabled', true)
        ->call('save')
        ->assertHasErrors(['esi_number' => 'required_if']);
});

test('ot rate per hour is required when ot eligible is enabled', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hrAdmin)
        ->test(EmployeeCreate::class)
        ->set('name', 'Jane Payroll')
        ->set('email', 'jane.ot@conexus-ns.com')
        ->set('ot_eligible', true)
        ->call('save')
        ->assertHasErrors(['ot_rate_per_hour' => 'required_if']);
});

test('ifsc, pan and aadhar numbers are validated against their formats', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hrAdmin)
        ->test(EmployeeCreate::class)
        ->set('name', 'Jane Payroll')
        ->set('email', 'jane.formats@conexus-ns.com')
        ->set('ifsc_code', 'not-an-ifsc')
        ->set('pan_number', 'not-a-pan')
        ->set('aadhar_number', '12345')
        ->call('save')
        ->assertHasErrors(['ifsc_code' => 'regex', 'pan_number' => 'regex', 'aadhar_number' => 'regex']);
});
