<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Models\EmployeeSalary;
use Illuminate\Database\Seeder;

class PayrollSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create standard salary components
        $components = [
            ['name' => 'Basic Salary', 'type' => 'earning', 'is_fixed' => true, 'default_amount' => 50000],
            ['name' => 'HRA', 'type' => 'earning', 'is_fixed' => true, 'default_amount' => 20000],
            ['name' => 'Special Allowance', 'type' => 'earning', 'is_fixed' => true, 'default_amount' => 10000],
            ['name' => 'Professional Tax', 'type' => 'deduction', 'is_fixed' => true, 'default_amount' => 200],
            ['name' => 'Provident Fund (PF)', 'type' => 'deduction', 'is_fixed' => true, 'default_amount' => 1800],
            ['name' => 'Health Insurance', 'type' => 'deduction', 'is_fixed' => true, 'default_amount' => 1500],
        ];

        foreach ($components as $comp) {
            SalaryComponent::firstOrCreate(['name' => $comp['name']], $comp);
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
