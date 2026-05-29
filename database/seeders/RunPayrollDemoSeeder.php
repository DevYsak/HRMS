<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\JobTitle;
use App\Models\Office;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\SalaryComponent;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RunPayrollDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Salary components ────────────────────────────────────────────────
        $components = [
            ['name' => 'Basic Salary',        'type' => 'earning',   'is_fixed' => true, 'default_amount' => 50000, 'is_active' => true],
            ['name' => 'HRA',                  'type' => 'earning',   'is_fixed' => true, 'default_amount' => 20000, 'is_active' => true],
            ['name' => 'Special Allowance',    'type' => 'earning',   'is_fixed' => true, 'default_amount' => 10000, 'is_active' => true],
            ['name' => 'Professional Tax',     'type' => 'deduction', 'is_fixed' => true, 'default_amount' => 200, 'is_active' => true],
            ['name' => 'Provident Fund (PF)',  'type' => 'deduction', 'is_fixed' => true, 'default_amount' => 1800, 'is_active' => true],
            ['name' => 'Health Insurance',     'type' => 'deduction', 'is_fixed' => true, 'default_amount' => 1500, 'is_active' => true],
        ];
        foreach ($components as $comp) {
            SalaryComponent::firstOrCreate(['name' => $comp['name']], $comp);
        }
        $salaryComponents = SalaryComponent::where('is_active', true)->get()->keyBy('name');

        // ── 2. Ensure departments exist ─────────────────────────────────────────
        $deptNames = ['Production', 'Logistics', 'Marketing', 'HR', 'IT', 'Finance', 'Admin'];
        $deptCodes = ['PRD', 'LOG', 'MKT', 'HR', 'IT', 'FIN', 'ADMIN'];
        $company = Company::first();
        foreach (array_combine($deptCodes, $deptNames) as $code => $name) {
            Department::firstOrCreate(['code' => $code], [
                'name' => $name,
                'company_id' => $company?->id,
            ]);
        }

        // ── 3. Ensure job titles exist ──────────────────────────────────────────
        $titleNames = [
            'Production Manager', 'Production Executive', 'Logistics Manager', 'Logistics Coordinator',
            'Marketing Manager',  'Marketing Executive',  'HR Manager',        'HR Executive',
            'IT Manager',         'Software Engineer',    'Finance Manager',    'Finance Executive',
            'Admin Manager',
        ];
        foreach ($titleNames as $title) {
            JobTitle::firstOrCreate(['name' => $title], ['name' => $title, 'company_id' => $company?->id]);
        }

        $office = Office::first();
        $shift = ShiftSetting::first();

        // ── 4. 37 demo employees with realistic salary data ─────────────────────
        // Format: [name, dept_code, job_title, gross, deductions, email_prefix]
        $employees = [
            // First 6 matching reference screenshot exactly
            ['Yogesh Sakpal',  'PRD', 'Production Manager',    85000, 11500, 'yogesh.sakpal'],
            ['Ajay Patil',     'LOG', 'Logistics Manager',     78500, 10200, 'ajay.patil'],
            ['Riya Sharma',    'MKT', 'Marketing Manager',     92000, 12750, 'riya.sharma'],
            ['Shivani Verma',  'HR',  'HR Manager',            88750, 11300, 'shivani.verma'],
            ['Mazhar Khan',    'IT',  'IT Manager',           102000, 13450, 'mazhar.khan'],
            ['Nick Patel',     'FIN', 'Finance Manager',       75600,  9250, 'nick.patel'],
            // Additional employees
            ['Priya Mehta',    'PRD', 'Production Executive',  62000,  8500, 'priya.mehta'],
            ['Rohit Singh',    'LOG', 'Logistics Coordinator', 58000,  7800, 'rohit.singh'],
            ['Anita Desai',    'MKT', 'Marketing Executive',   67500,  9100, 'anita.desai'],
            ['Sunita Joshi',   'HR',  'HR Executive',          54000,  7200, 'sunita.joshi'],
            ['Vikram Nair',    'IT',  'Software Engineer',     95000, 12800, 'vikram.nair'],
            ['Kavita Reddy',   'FIN', 'Finance Executive',     61000,  8200, 'kavita.reddy'],
            ['Arjun Pandey',   'PRD', 'Production Executive',  60000,  8100, 'arjun.pandey'],
            ['Pooja Gupta',    'MKT', 'Marketing Executive',   65000,  8800, 'pooja.gupta'],
            ['Rahul Verma',    'IT',  'Software Engineer',     88000, 11900, 'rahul.verma'],
            ['Meera Shah',     'HR',  'HR Executive',          52000,  7000, 'meera.shah'],
            ['Amit Kumar',     'FIN', 'Finance Executive',     63000,  8500, 'amit.kumar'],
            ['Neha Sharma',    'LOG', 'Logistics Coordinator', 55000,  7400, 'neha.sharma'],
            ['Suresh Pillai',  'IT',  'Software Engineer',     91000, 12300, 'suresh.pillai'],
            ['Deepa Nambiar',  'PRD', 'Production Executive',  58500,  7900, 'deepa.nambiar'],
            ['Karan Malhotra', 'MKT', 'Marketing Executive',   70000,  9500, 'karan.malhotra'],
            ['Shreya Iyer',    'FIN', 'Finance Executive',     59000,  7950, 'shreya.iyer'],
            ['Sanjay Dubey',   'LOG', 'Logistics Coordinator', 56000,  7600, 'sanjay.dubey'],
            ['Lakshmi Rao',    'IT',  'Software Engineer',     86000, 11600, 'lakshmi.rao'],
            ['Ravi Shankar',   'PRD', 'Production Executive',  61500,  8300, 'ravi.shankar'],
            ['Preeti Kapoor',  'HR',  'HR Executive',          53000,  7100, 'preeti.kapoor'],
            ['Gaurav Mishra',  'IT',  'Software Engineer',     89500, 12100, 'gaurav.mishra'],
            ['Anjali Singh',   'MKT', 'Marketing Executive',   68000,  9200, 'anjali.singh'],
            ['Dinesh Tiwari',  'FIN', 'Finance Executive',     64000,  8600, 'dinesh.tiwari'],
            ['Harish Yadav',   'LOG', 'Logistics Coordinator', 57000,  7700, 'harish.yadav'],
            ['Smita Bhat',     'PRD', 'Production Executive',  59500,  8000, 'smita.bhat'],
            ['Rajesh Naik',    'IT',  'Software Engineer',     93000, 12500, 'rajesh.naik'],
            ['Vandana Kulkarni', 'HR', 'HR Executive',          51000,  6900, 'vandana.kulkarni'],
            ['Mohit Agarwal',  'FIN', 'Finance Executive',     62000,  8400, 'mohit.agarwal'],
            ['Tanvi Jain',     'MKT', 'Marketing Executive',   66000,  8900, 'tanvi.jain'],
            ['Sachin Patil',   'LOG', 'Logistics Coordinator', 54500,  7300, 'sachin.patil'],
            ['Nandita Bose',   'ADMIN', 'Admin Manager',        72000,  9700, 'nandita.bose'],
        ];

        $createdEmployees = [];

        foreach ($employees as $index => $data) {
            [$fullName, $deptCode, $jobTitleName, $gross, $totalDeductions, $emailPrefix] = $data;

            $empNum = 12 + $index; // EMP0012, EMP0013 …
            $empCode = 'EMP'.str_pad($empNum, 4, '0', STR_PAD_LEFT);
            $email = $emailPrefix.'@conexus-ns.com';

            // User
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $fullName,
                    'password' => Hash::make('password'),
                    'role' => 'employee',
                ]
            );

            $dept = Department::where('code', $deptCode)->first();
            $jobTitle = JobTitle::where('name', $jobTitleName)->first();

            $employee = Employee::where('user_id', $user->id)->first();
            if (! $employee) {
                $employee = Employee::create([
                    'user_id' => $user->id,
                    'employee_id' => $empCode,
                    'department_id' => $dept?->id,
                    'job_title_id' => $jobTitle?->id,
                    'office_id' => $office?->id,
                    'shift_id' => $shift?->id,
                    'joining_date' => now()->subMonths(rand(6, 36)),
                    'status' => 'active',
                    'salary_cycle' => 'cycle_a',
                ]);
            } else {
                $employee->update([
                    'employee_id' => $employee->employee_id ?? $empCode,
                    'department_id' => $dept?->id,
                    'job_title_id' => $jobTitle?->id,
                    'salary_cycle' => 'cycle_a',
                    'status' => 'active',
                ]);
            }

            // Assign salary components to match target gross/deduction totals.
            // We'll distribute evenly across earning and deduction components.
            $earningComponents = $salaryComponents->filter(fn ($c) => $c->type === 'earning');
            $deductionComponents = $salaryComponents->filter(fn ($c) => $c->type === 'deduction');

            $earningCount = $earningComponents->count();
            $deductionCount = $deductionComponents->count();

            $earningAmounts = $this->distributeAmount($gross, $earningCount);
            $deductionAmounts = $this->distributeAmount($totalDeductions, $deductionCount);

            $earningList = $earningComponents->values();
            $deductionList = $deductionComponents->values();

            foreach ($earningList as $i => $comp) {
                EmployeeSalary::updateOrCreate(
                    ['employee_id' => $employee->id, 'salary_component_id' => $comp->id],
                    ['amount' => $earningAmounts[$i]]
                );
            }
            foreach ($deductionList as $i => $comp) {
                EmployeeSalary::updateOrCreate(
                    ['employee_id' => $employee->id, 'salary_component_id' => $comp->id],
                    ['amount' => $deductionAmounts[$i]]
                );
            }

            $createdEmployees[] = [
                'employee' => $employee,
                'gross' => $gross,
                'deductions' => $totalDeductions,
                'net' => $gross - $totalDeductions,
                'index' => $index,
            ];
        }

        // ── 5. Create May 2026 payroll (pending_finance) ────────────────────────
        $payroll = Payroll::firstOrCreate(
            ['month' => 'May', 'year' => 2026, 'cycle' => 'cycle_a'],
            [
                'status' => 'pending_finance',
                'total_payout' => collect($createdEmployees)->sum('net'),
                'processed_by' => User::first()?->id,
                'processed_at' => now()->subHours(2),
            ]
        );

        // If already exists, sync status so UI shows pending_finance
        if ($payroll->wasRecentlyCreated === false && $payroll->status === 'draft') {
            $payroll->update(['status' => 'pending_finance']);
        }

        // ── 6. Create payslips ──────────────────────────────────────────────────
        // First 2 get 'pending_finance' status to match screenshot mixed statuses;
        // rest are 'draft'.
        foreach ($createdEmployees as $i => $row) {
            $slipStatus = $i < 2 ? 'draft' : 'draft'; // all draft — payroll-level handles pending_finance

            $payslip = Payslip::updateOrCreate(
                ['payroll_id' => $payroll->id, 'employee_id' => $row['employee']->id],
                [
                    'gross_salary' => $row['gross'],
                    'total_deductions' => $row['deductions'],
                    'net_salary' => $row['net'],
                    'status' => $slipStatus,
                ]
            );

            // Payslip items (clear and re-create)
            PayslipItem::where('payslip_id', $payslip->id)->delete();

            $empSalaries = EmployeeSalary::where('employee_id', $row['employee']->id)
                ->with('component')
                ->get();

            foreach ($empSalaries as $salary) {
                if (! $salary->component || ! $salary->component->is_active) {
                    continue;
                }
                PayslipItem::create([
                    'payslip_id' => $payslip->id,
                    'name' => $salary->component->name,
                    'amount' => $salary->amount,
                    'type' => $salary->component->type,
                ]);
            }
        }

        $totalNet = collect($createdEmployees)->sum('net');
        $payroll->update(['total_payout' => $totalNet]);

        $this->command->info('RunPayrollDemoSeeder: seeded 37 employees + May 2026 payroll (pending_finance).');
    }

    /**
     * Distribute a total amount across N slots, keeping integer values.
     * The first slot absorbs any rounding remainder.
     */
    private function distributeAmount(int $total, int $slots): array
    {
        if ($slots === 0) {
            return [];
        }
        $base = intdiv($total, $slots);
        $remainder = $total - ($base * $slots);
        $amounts = array_fill(0, $slots, $base);
        $amounts[0] += $remainder;

        return $amounts;
    }
}
