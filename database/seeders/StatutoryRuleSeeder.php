<?php

namespace Database\Seeders;

use App\Enums\StatutoryRuleType;
use App\Models\StatutoryRule;
use Illuminate\Database\Seeder;

/**
 * Seeds the statutory rates the engine previously carried as PHP constants.
 *
 * Values here are a faithful transcription of the old StatutoryService constants
 * — seeding is a refactor, not a rate change, and payslips must not move.
 *
 * Each row is dated from when that rate actually came into force and left
 * open-ended, so today's behaviour is preserved going forward. When a Finance Act
 * revises a rate, close the current row with an effective_to and open a successor
 * rather than editing in place: historical payslips must keep resolving the rates
 * they were computed under.
 *
 * Idempotent — safe to re-run; it will not duplicate or overwrite edited rows.
 */
class StatutoryRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rules() as $rule) {
            StatutoryRule::firstOrCreate(
                [
                    'type' => $rule['type'],
                    'jurisdiction' => $rule['jurisdiction'] ?? null,
                    'regime' => $rule['regime'] ?? null,
                    'effective_from' => $rule['effective_from'],
                ],
                $rule,
            );
        }

        $this->command?->info('Statutory rules: '.StatutoryRule::count().' rule(s) present.');
    }

    /** @return array<int, array<string, mixed>> */
    private function rules(): array
    {
        return [
            [
                'type' => StatutoryRuleType::ProvidentFund->value,
                'label' => 'EPF — 12% of PF wage, ₹15,000 ceiling',
                // Ceiling rose from ₹6,500 to ₹15,000 with effect from 1 Sep 2014.
                'effective_from' => '2014-09-01',
                'config' => [
                    'rate' => 0.12,
                    'wage_ceiling' => 15000.0,
                    'eps_rate' => 0.0833,
                ],
                'notes' => 'Employee 12% of (Basic + DA) capped at the wage ceiling → max ₹1,800/month. '
                    .'eps_rate is the employer share diverted to the pension scheme.',
            ],
            [
                'type' => StatutoryRuleType::EmployeeStateInsurance->value,
                'label' => 'ESI — 0.75% employee / 3.25% employer, ₹21,000 ceiling',
                // Rates cut from 1.75%/4.75% with effect from 1 Jul 2019.
                'effective_from' => '2019-07-01',
                'config' => [
                    'employee_rate' => 0.0075,
                    'employer_rate' => 0.0325,
                    'wage_ceiling' => 21000.0,
                ],
                'notes' => 'Applies only when monthly gross is within the coverage ceiling. '
                    .'Contributions round up to the next rupee per ESIC rules.',
            ],
            [
                'type' => StatutoryRuleType::ProfessionalTax->value,
                'jurisdiction' => 'MH',
                'label' => 'Professional Tax — Maharashtra',
                'effective_from' => '2023-04-01',
                'config' => [
                    'slabs' => [
                        ['up_to' => 7500.0, 'amount' => 0.0],
                        ['up_to' => 10000.0, 'amount' => 175.0],
                        // February carries the balance to reach the ₹2,500 annual cap.
                        // Scoped to this slab: a ₹9,000 earner still pays ₹175 in February.
                        ['up_to' => null, 'amount' => 200.0, 'month_overrides' => ['2' => 300.0]],
                    ],
                    'female_exemption_up_to' => 25000.0,
                ],
                'notes' => 'Maharashtra slab. Other states must be added as separate '
                    .'jurisdiction-scoped rows before payroll runs for employees based there.',
            ],
            [
                'type' => StatutoryRuleType::IncomeTax->value,
                'regime' => 'new',
                'label' => 'Income Tax — New Regime (FY 2025-26)',
                'effective_from' => '2025-04-01',
                'config' => [
                    'standard_deduction' => 75000.0,
                    'rebate_87a_limit' => 1200000.0,
                    'cess_rate' => 0.04,
                    'slabs' => [
                        ['up_to' => 400000.0, 'rate' => 0.00],
                        ['up_to' => 800000.0, 'rate' => 0.05],
                        ['up_to' => 1200000.0, 'rate' => 0.10],
                        ['up_to' => 1600000.0, 'rate' => 0.15],
                        ['up_to' => 2000000.0, 'rate' => 0.20],
                        ['up_to' => 2400000.0, 'rate' => 0.25],
                        ['up_to' => null, 'rate' => 0.30],
                    ],
                ],
                'notes' => 'NEEDS REVIEW: transcribed from the FY 2025-26 constants and left open-ended '
                    .'so behaviour is unchanged. Verify against the current Finance Act before running '
                    .'FY 2026-27 payroll; if rates moved, close this row at 2026-03-31 and open a successor.',
            ],
        ];
    }
}
