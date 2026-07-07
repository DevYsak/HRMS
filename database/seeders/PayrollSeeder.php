<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\SalaryComponent;
use Illuminate\Database\Seeder;

class PayrollSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Standard salary components. Statutory heads carry the codes/flags
        //    the SalaryCalculationService uses to compute EPF/ESI/PT/TDS to
        //    policy (see App\Services\StatutoryService).
        $components = [
            ['name' => 'Basic Salary', 'code' => 'BASIC', 'type' => 'earning', 'component_type' => 'earning', 'is_fixed' => true, 'default_amount' => 50000, 'is_taxable' => true, 'display_order' => 1],
            ['name' => 'HRA', 'code' => 'HRA', 'type' => 'earning', 'component_type' => 'earning', 'is_fixed' => true, 'default_amount' => 20000, 'is_taxable' => true, 'display_order' => 2],
            ['name' => 'Special Allowance', 'code' => 'SPECIAL', 'type' => 'earning', 'component_type' => 'earning', 'is_fixed' => true, 'default_amount' => 10000, 'is_taxable' => true, 'display_order' => 3],
            ['name' => 'Provident Fund (PF)', 'code' => 'PF', 'type' => 'deduction', 'component_type' => 'deduction', 'is_fixed' => true, 'default_amount' => 1800, 'is_pf_applicable' => true, 'display_order' => 1],
            ['name' => 'ESI', 'code' => 'ESI', 'type' => 'deduction', 'component_type' => 'deduction', 'is_fixed' => false, 'default_amount' => 0, 'is_esi_applicable' => true, 'display_order' => 2],
            ['name' => 'Professional Tax', 'code' => 'PROFESSIONAL_TAX', 'type' => 'deduction', 'component_type' => 'deduction', 'is_fixed' => true, 'default_amount' => 200, 'display_order' => 3],
            ['name' => 'Income Tax (TDS)', 'code' => 'TDS', 'type' => 'deduction', 'component_type' => 'deduction', 'is_fixed' => false, 'default_amount' => 0, 'display_order' => 4],
            ['name' => 'Health Insurance', 'code' => 'HEALTH_INS', 'type' => 'deduction', 'component_type' => 'deduction', 'is_fixed' => true, 'default_amount' => 1500, 'display_order' => 5],
        ];

        // updateOrCreate keyed on name (stable across existing installs) so
        // re-seeding backfills the codes/flags onto components created before
        // statutory support existed, and adds ESI / TDS where missing.
        foreach ($components as $comp) {
            SalaryComponent::updateOrCreate(['name' => $comp['name']], $comp);
        }

        // 2. Assign components to some employees for testing
        $employees = Employee::take(5)->get();
        $salaryComponents = SalaryComponent::all();

        foreach ($employees as $emp) {
            foreach ($salaryComponents as $comp) {
                EmployeeSalary::firstOrCreate(
                    ['employee_id' => $emp->id, 'salary_component_id' => $comp->id],
                    ['amount' => $comp->default_amount]
                );
            }
        }
    }
}
