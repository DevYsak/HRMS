<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeCreate;
use App\Livewire\Payroll\SalaryStructures;
use App\Models\EmployeeSalary;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('hr admin can create a salary structure with linked components', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $basic = SalaryComponent::create(['name' => 'Basic Salary', 'type' => 'earning', 'default_amount' => 20000]);
    $hra = SalaryComponent::create(['name' => 'HRA', 'type' => 'earning', 'default_amount' => 8000]);

    Livewire::actingAs($hrAdmin)
        ->test(SalaryStructures::class)
        ->call('create')
        ->set('form.name', 'Standard Employee')
        ->set('form.code', 'STD_EMP_TEST')
        ->set('form.description', 'Default structure')
        ->set('form.is_active', true)
        ->set("selectedComponents.{$basic->id}", true)
        ->set("componentAmounts.{$basic->id}", 25000)
        ->set("selectedComponents.{$hra->id}", true)
        ->call('save')
        ->assertHasNoErrors();

    $structure = SalaryStructure::where('code', 'STD_EMP_TEST')->first();

    expect($structure)->not->toBeNull()
        ->and($structure->is_active)->toBeTrue()
        ->and($structure->components)->toHaveCount(2);

    $basicPivot = $structure->components->firstWhere('id', $basic->id)->pivot;
    $hraPivot = $structure->components->firstWhere('id', $hra->id)->pivot;

    expect((float) $basicPivot->amount)->toBe(25000.0)
        ->and($hraPivot->amount)->toBeNull();
});

test('hr admin can edit a salary structure and update its components', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $structure = SalaryStructure::create(['name' => 'Manager', 'code' => 'MGR_TEST', 'is_active' => true]);
    $basic = SalaryComponent::create(['name' => 'Basic Salary', 'type' => 'earning', 'default_amount' => 30000]);

    Livewire::actingAs($hrAdmin)
        ->test(SalaryStructures::class)
        ->call('edit', $structure->id)
        ->set('form.name', 'Senior Manager Renamed')
        ->set("selectedComponents.{$basic->id}", true)
        ->set("componentAmounts.{$basic->id}", 50000)
        ->call('save')
        ->assertHasNoErrors();

    $structure->refresh();

    expect($structure->name)->toBe('Senior Manager Renamed')
        ->and($structure->components)->toHaveCount(1)
        ->and((float) $structure->components->first()->pivot->amount)->toBe(50000.0);
});

test('hr admin can activate and deactivate a salary structure', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $structure = SalaryStructure::create(['name' => 'Intern', 'code' => 'INTERN_TEST', 'is_active' => true]);

    Livewire::actingAs($hrAdmin)
        ->test(SalaryStructures::class)
        ->call('toggleActive', $structure->id);

    expect($structure->refresh()->is_active)->toBeFalse();

    Livewire::actingAs($hrAdmin)
        ->test(SalaryStructures::class)
        ->call('toggleActive', $structure->id);

    expect($structure->refresh()->is_active)->toBeTrue();
});

test('hr admin can soft delete and restore a salary structure', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $structure = SalaryStructure::create(['name' => 'Contract Employee', 'code' => 'CONTRACT_TEST', 'is_active' => true]);

    Livewire::actingAs($hrAdmin)
        ->test(SalaryStructures::class)
        ->call('delete', $structure->id);

    expect(SalaryStructure::find($structure->id))->toBeNull()
        ->and(SalaryStructure::withTrashed()->find($structure->id))->not->toBeNull();

    Livewire::actingAs($hrAdmin)
        ->test(SalaryStructures::class)
        ->call('restore', $structure->id);

    expect(SalaryStructure::find($structure->id))->not->toBeNull();
});

test('only active salary structures are offered on the employee create form', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    $active = SalaryStructure::create(['name' => 'Active Structure', 'code' => 'ACTIVE_TEST', 'is_active' => true]);
    $inactive = SalaryStructure::create(['name' => 'Inactive Structure', 'code' => 'INACTIVE_TEST', 'is_active' => false]);

    Livewire::actingAs($hrAdmin)
        ->test(EmployeeCreate::class)
        ->assertSee('Active Structure')
        ->assertDontSee('Inactive Structure');
});

test('employee create form shows a message when no salary structures are configured', function () {
    SalaryStructure::query()->forceDelete();

    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hrAdmin)
        ->test(EmployeeCreate::class)
        ->assertSee('No salary structures configured');
});

test('selecting a salary structure on employee create assigns its components as employee salaries', function () {
    Mail::fake();
    Notification::fake();

    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    $structure = SalaryStructure::create(['name' => 'Standard Employee', 'code' => 'STD_EMP_INTEGRATION', 'is_active' => true]);
    $basic = SalaryComponent::create(['name' => 'Basic Salary', 'type' => 'earning', 'default_amount' => 20000]);
    $hra = SalaryComponent::create(['name' => 'HRA', 'type' => 'earning', 'default_amount' => 8000]);

    $structure->components()->sync([
        $basic->id => ['amount' => 22000],
        $hra->id => ['amount' => null],
    ]);

    Livewire::actingAs($hrAdmin)
        ->test(EmployeeCreate::class)
        ->set('name', 'Jane Structure')
        ->set('email', 'jane.structure@conexus-ns.com')
        ->set('salary_structure_id', (string) $structure->id)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'jane.structure@conexus-ns.com')->first();
    $salaries = EmployeeSalary::where('employee_id', $user->employee->id)->get();

    expect($salaries)->toHaveCount(2);

    $basicSalary = $salaries->firstWhere('salary_component_id', $basic->id);
    $hraSalary = $salaries->firstWhere('salary_component_id', $hra->id);

    expect((float) $basicSalary->amount)->toBe(22000.0)
        ->and((float) $hraSalary->amount)->toBe(8000.0);
});
