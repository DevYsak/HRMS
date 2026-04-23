<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create the demo company
        $company = Company::create([
            'name' => 'Conexus Technologies',
            'website' => 'https://conexus.in',
            'industry' => 'Technology',
            'phone' => '+91 98765 43210',
            'email' => 'hr@conexus.in',
            'address' => '42, Tech Park, Whitefield',
            'city' => 'Bangalore',
            'country' => 'India',
            'timezone' => 'Asia/Kolkata',
            'date_format' => 'd M Y',
            'currency' => 'INR',
            'currency_symbol' => '₹',
        ]);

        // Offices
        $headOffice = Office::create([
            'company_id' => $company->id,
            'name' => 'Head Office — Bangalore',
            'address' => '42, Tech Park, Whitefield',
            'city' => 'Bangalore',
            'country' => 'India',
            'timezone' => 'Asia/Kolkata',
            'is_headquarters' => true,
        ]);

        Office::create([
            'company_id' => $company->id,
            'name' => 'Mumbai Office',
            'address' => 'BKC, Bandra Kurla Complex',
            'city' => 'Mumbai',
            'country' => 'India',
            'timezone' => 'Asia/Kolkata',
            'is_headquarters' => false,
        ]);

        Office::create([
            'company_id' => $company->id,
            'name' => 'Delhi Office',
            'address' => 'Cyber City, Gurugram',
            'city' => 'Delhi NCR',
            'country' => 'India',
            'timezone' => 'Asia/Kolkata',
            'is_headquarters' => false,
        ]);

        // Departments
        $departments = [
            ['name' => 'Engineering',       'code' => 'ENG'],
            ['name' => 'Product',           'code' => 'PRD'],
            ['name' => 'Design',            'code' => 'DES'],
            ['name' => 'Marketing',         'code' => 'MKT'],
            ['name' => 'Human Resources',   'code' => 'HR'],
            ['name' => 'Finance',           'code' => 'FIN'],
            ['name' => 'Operations',        'code' => 'OPS'],
            ['name' => 'Sales',             'code' => 'SLS'],
        ];

        foreach ($departments as $dept) {
            Department::create([
                'company_id' => $company->id,
                'name' => $dept['name'],
                'code' => $dept['code'],
            ]);
        }

        // Job Titles
        $titles = [
            'Software Engineer', 'Senior Software Engineer', 'Lead Engineer', 'Engineering Manager',
            'UI UX Designer', 'Graphic Designer', 'Creative Director',
            'Product Manager', 'Senior Product Manager',
            'HR Manager', 'HR Executive', 'Talent Acquisition Specialist',
            'Finance Analyst', 'Finance Manager',
            'Marketing Manager', 'Content Writer', 'Growth Manager',
            'Project Manager', 'Operations Manager',
            'Sales Executive', 'Sales Manager',
        ];

        foreach ($titles as $title) {
            JobTitle::create([
                'company_id' => $company->id,
                'name' => $title,
            ]);
        }

        // Admin user
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@conexus.in',
            'role' => UserRole::Admin,
        ]);
        \App\Models\Employee::factory()->create([
            'user_id' => $admin->id,
            'employee_id' => 'EMP-0001',
            'manager_id' => null,
            'status' => 'active',
        ]);

        // HR user
        $hr = User::factory()->create([
            'name' => 'Pristia Candra',
            'email' => 'pristia@conexus.in',
            'role' => UserRole::Hr,
            'avatar' => null, // Let the fallback handle it
        ]);
        \App\Models\Employee::factory()->create([
            'user_id' => $hr->id,
            'employee_id' => 'EMP-0002',
            'department_id' => Department::where('code', 'HR')->first()?->id,
            'manager_id' => $admin->id,
            'status' => 'active',
        ]);

        // Manager
        $manager = User::factory()->create([
            'name' => 'Rayna Torff',
            'email' => 'rayna@conexus.in',
            'role' => UserRole::Manager,
        ]);
        \App\Models\Employee::factory()->create([
            'user_id' => $manager->id,
            'employee_id' => 'EMP-0003',
            'department_id' => Department::where('code', 'PRD')->first()?->id,
            'manager_id' => $admin->id,
            'status' => 'active',
        ]);

        // Demo employee
        $employee = User::factory()->create([
            'name' => 'Test Employee',
            'email' => 'test@example.com',
            'role' => UserRole::Employee,
        ]);
        \App\Models\Employee::factory()->create([
            'user_id' => $employee->id,
            'employee_id' => 'EMP-0004',
            'department_id' => Department::where('code', 'ENG')->first()?->id,
            'manager_id' => $manager->id,
            'status' => 'active',
        ]);

        // Seed 50 more random employees
        User::factory(50)->create(['role' => UserRole::Employee])->each(function ($user) {
            \App\Models\Employee::factory()->create([
                'user_id' => $user->id,
            ]);
        });
    }
}
