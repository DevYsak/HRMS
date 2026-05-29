<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\SalaryComponent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PayrollDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Clear existing payroll data
        PayslipItem::truncate();
        Payslip::truncate();
        Payroll::truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Find the first super_admin user to act as processor
        $adminId = DB::table('users')->where('role', 'super_admin')->value('id') ?? DB::table('users')->value('id');

        $components = SalaryComponent::all()->keyBy('id');
        $employees = Employee::with(['user', 'salaries.component', 'department'])
            ->whereHas('user')
            ->where('status', 'active')
            ->get();

        if ($employees->isEmpty()) {
            $this->command->warn('No active employees found. Skipping payroll seeder.');

            return;
        }

        // Generate 5 months: Jan → Apr finalized, May pending_finance
        $months = [
            ['month' => 'January',  'year' => 2026, 'status' => 'finalized',       'pct' => 1.00],
            ['month' => 'February', 'year' => 2026, 'status' => 'finalized',       'pct' => 1.00],
            ['month' => 'March',    'year' => 2026, 'status' => 'finalized',       'pct' => 1.02],
            ['month' => 'April',    'year' => 2026, 'status' => 'finalized',       'pct' => 1.02],
            ['month' => 'May',      'year' => 2026, 'status' => 'pending_finance', 'pct' => 1.05],
        ];

        foreach ($months as $m) {
            $totalPayout = 0;
            $totalDeductions = 0;
            $processedAt = now()->subMonths(5 - array_search($m, $months))->endOfMonth();

            $payroll = Payroll::create([
                'month' => $m['month'],
                'year' => $m['year'],
                'cycle' => 'cycle_a',
                'status' => $m['status'],
                'total_payout' => 0,
                'ot_amount' => 0,
                'incentives' => 0,
                'reimbursements' => 0,
                'deductions' => 0,
                'processed_by' => $adminId,
                'processed_at' => $processedAt,
                'finance_approved_at' => $m['status'] === 'finalized' ? $processedAt->addDay() : null,
            ]);

            foreach ($employees as $employee) {
                $salaries = EmployeeSalary::with('component')
                    ->where('employee_id', $employee->id)
                    ->get();

                if ($salaries->isEmpty()) {
                    continue;
                }

                $gross = 0;
                $deductions = 0;
                $items = [];

                foreach ($salaries as $salary) {
                    $comp = $salary->component;
                    if (! $comp) {
                        continue;
                    }
                    $amount = round($salary->amount * $m['pct'], 2);

                    if ($comp->type === 'earning') {
                        $gross += $amount;
                    } else {
                        $deductions += $amount;
                    }

                    $items[] = [
                        'name' => $comp->name,
                        'amount' => $amount,
                        'type' => $comp->type,
                    ];
                }

                // Add TDS based on annual gross
                $annualGross = $gross * 12;
                $tds = $annualGross > 500000 ? round(($annualGross * 0.10) / 12, 2) : 0;
                if ($tds > 0) {
                    $items[] = ['name' => 'Income Tax (TDS)', 'amount' => $tds, 'type' => 'deduction'];
                    $deductions += $tds;
                }

                $net = $gross - $deductions;
                $totalPayout += $net;
                $totalDeductions += $deductions;

                $payslip = Payslip::create([
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employee->id,
                    'gross_salary' => $gross,
                    'total_deductions' => $deductions,
                    'net_salary' => $net,
                    'status' => $m['status'] === 'finalized' ? 'paid' : 'draft',
                ]);

                foreach ($items as $item) {
                    PayslipItem::create([
                        'payslip_id' => $payslip->id,
                        'name' => $item['name'],
                        'amount' => $item['amount'],
                        'type' => $item['type'],
                    ]);
                }
            }

            $payroll->update([
                'total_payout' => round($totalPayout, 2),
                'deductions' => round($totalDeductions, 2),
            ]);

            $this->command->info("  ✓ {$m['month']} {$m['year']} — {$employees->count()} payslips, ₹".number_format($totalPayout, 2));
        }

        $this->command->info('Payroll demo data seeded successfully.');
    }
}
