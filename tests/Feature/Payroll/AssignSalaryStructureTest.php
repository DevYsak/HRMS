<?php

use App\Livewire\Payroll\SalaryStructures;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\SalaryComponent;
use App\Models\SalaryRevision;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Services\PayrollService;
use Livewire\Livewire;

/**
 * Phase 4: closes a real gap found while verifying salary_revisions wiring —
 * structures could be defined but never actually assigned to an existing
 * employee outside of the one-time EmployeeCreate flow. This mirrors
 * IncrementService::applySalaryUplift()'s effective-dating + SalaryRevision
 * convention for the "assign/change structure" path.
 */
function structureEmployee(string $name): Employee
{
    $user = User::factory()->create(['name' => $name]);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

test('assigning a structure closes old salary rows, opens new ones, and records a revision', function () {
    $hrAdmin = User::factory()->create(['role' => 'hr_admin']);
    $employee = structureEmployee('Priya');

    $oldBasic = SalaryComponent::create([
        'name' => 'Old Basic', 'type' => 'earning', 'component_type' => 'earning', 'default_amount' => 20000,
    ]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $oldBasic->id, 'amount' => 20000]);

    $newBasic = SalaryComponent::create([
        'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning', 'default_amount' => 25000,
    ]);
    $hra = SalaryComponent::create([
        'name' => 'HRA', 'type' => 'earning', 'component_type' => 'earning', 'default_amount' => 10000,
    ]);
    $structure = SalaryStructure::create(['name' => 'Senior Structure', 'code' => 'SENIOR_TEST', 'is_active' => true]);
    $structure->components()->sync([
        $newBasic->id => ['amount' => 30000],
        $hra->id => ['amount' => null],
    ]);

    $revision = app(PayrollService::class)->assignSalaryStructure(
        $structure, $employee, $hrAdmin, '2026-08-01', 'Promotion to Senior',
    );

    // Old row closed out (not deleted), new rows opened from the effective date.
    $oldRow = EmployeeSalary::where('employee_id', $employee->id)->where('salary_component_id', $oldBasic->id)->first();
    expect($oldRow->effective_to->toDateString())->toBe('2026-07-31');

    $activeRows = EmployeeSalary::where('employee_id', $employee->id)->whereNull('effective_to')->get();
    expect($activeRows)->toHaveCount(2);

    $newBasicRow = $activeRows->firstWhere('salary_component_id', $newBasic->id);
    $hraRow = $activeRows->firstWhere('salary_component_id', $hra->id);
    expect((float) $newBasicRow->amount)->toBe(30000.0)
        ->and((float) $hraRow->amount)->toBe(10000.0)
        ->and($newBasicRow->effective_from->toDateString())->toBe('2026-08-01');

    expect($revision->old_ctc)->toEqualWithDelta(20000 * 12, 0.01)
        ->and($revision->new_ctc)->toEqualWithDelta((30000 + 10000) * 12, 0.01)
        ->and($revision->reason)->toBe('Promotion to Senior')
        ->and($revision->approved_by)->toBe($hrAdmin->id);

    $settings = EmployeePayrollSettings::where('employee_id', $employee->id)->first();
    expect($settings)->not->toBeNull()
        ->and($settings->salary_structure_id)->toBe($structure->id)
        ->and((float) $settings->ctc)->toEqualWithDelta((30000 + 10000) * 12, 0.01);

    expect(AuditLog::where('auditable_type', SalaryRevision::class)->where('auditable_id', $revision->id)->exists())->toBeTrue();
});

test('assigning a structure to an employee with no prior payroll settings creates them', function () {
    $hrAdmin = User::factory()->create(['role' => 'hr_admin']);
    $employee = structureEmployee('New Hire');

    $basic = SalaryComponent::create(['name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning', 'default_amount' => 15000]);
    $structure = SalaryStructure::create(['name' => 'Junior Structure', 'code' => 'JUNIOR_TEST', 'is_active' => true]);
    $structure->components()->sync([$basic->id => ['amount' => null]]);

    expect(EmployeePayrollSettings::where('employee_id', $employee->id)->exists())->toBeFalse();

    app(PayrollService::class)->assignSalaryStructure($structure, $employee, $hrAdmin, '2026-08-01');

    $settings = EmployeePayrollSettings::where('employee_id', $employee->id)->first();
    expect($settings)->not->toBeNull()
        ->and($settings->salary_structure_id)->toBe($structure->id)
        ->and((float) $settings->ctc)->toEqualWithDelta(15000 * 12, 0.01);
});

test('hr admin can assign a salary structure to an employee from the structures screen', function () {
    $hrAdmin = User::factory()->create(['role' => 'hr_admin']);
    $employee = structureEmployee('Rahul');

    $basic = SalaryComponent::create(['name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning', 'default_amount' => 18000]);
    $structure = SalaryStructure::create(['name' => 'Standard Structure', 'code' => 'STD_ASSIGN_TEST', 'is_active' => true]);
    $structure->components()->sync([$basic->id => ['amount' => null]]);

    Livewire::actingAs($hrAdmin)
        ->test(SalaryStructures::class)
        ->call('openAssign', $structure->id)
        ->assertSet('showAssignModal', true)
        ->set('assignForm.employee_id', $employee->id)
        ->set('assignForm.effective_date', '2026-08-01')
        ->set('assignForm.reason', 'Initial assignment')
        ->call('assign')
        ->assertHasNoErrors()
        ->assertSet('showAssignModal', false);

    expect(SalaryRevision::where('employee_id', $employee->id)->exists())->toBeTrue();
    expect(EmployeePayrollSettings::where('employee_id', $employee->id)->first()->salary_structure_id)->toBe($structure->id);
});

test('assigning a structure without an employee fails validation', function () {
    $hrAdmin = User::factory()->create(['role' => 'hr_admin']);
    $structure = SalaryStructure::create(['name' => 'Standard Structure', 'code' => 'STD_VALIDATE_TEST', 'is_active' => true]);

    Livewire::actingAs($hrAdmin)
        ->test(SalaryStructures::class)
        ->call('openAssign', $structure->id)
        ->set('assignForm.employee_id', '')
        ->set('assignForm.effective_date', '2026-08-01')
        ->call('assign')
        ->assertHasErrors(['assignForm.employee_id']);
});
