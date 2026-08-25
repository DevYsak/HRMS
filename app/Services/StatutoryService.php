<?php

namespace App\Services;

use App\Enums\StatutoryRuleType;
use App\Services\Statutory\StatutoryRuleResolver;
use Carbon\CarbonInterface;

/**
 * Statutory Indian payroll calculations (EPF, ESI, Professional Tax, TDS).
 *
 * Every rate, ceiling and slab is resolved from the statutory_rules table for the
 * date being paid — nothing is hardcoded here. Two consequences worth knowing:
 *
 *  - A Finance Act revision is a data change, not a deploy. Close the current rule
 *    with an effective_to and open a successor.
 *  - Re-running a closed period resolves the rates that were in force then, so a
 *    reprinted payslip still reconciles with what was actually paid.
 *
 * An unconfigured period raises MissingStatutoryRuleException rather than falling
 * back to a built-in default: silently computing tax on rates nobody chose is the
 * failure mode this table exists to prevent.
 *
 * @see StatutoryRuleType for the config payload each rule type must carry.
 */
class StatutoryService
{
    public function __construct(
        private readonly StatutoryRuleResolver $resolver,
    ) {}

    /**
     * Employee EPF contribution: `rate` of PF wage (Basic + DA), capped at the
     * statutory wage ceiling. Rounded to whole rupees.
     */
    public function providentFundEmployee(float $pfWage, CarbonInterface $asOf): float
    {
        $config = $this->resolver->config(StatutoryRuleType::ProvidentFund, $asOf);
        $wage = min(max($pfWage, 0), (float) $config['wage_ceiling']);

        return round($wage * (float) $config['rate']);
    }

    /**
     * Employer EPF contribution (matches the employee share). Informational only —
     * not deducted from the employee's net pay.
     */
    public function providentFundEmployer(float $pfWage, CarbonInterface $asOf): float
    {
        return $this->providentFundEmployee($pfWage, $asOf);
    }

    /**
     * Employer share diverted to the Employees' Pension Scheme, on the capped wage.
     * Part of the employer contribution, surfaced for transparency.
     */
    public function pensionSchemeEmployer(float $pfWage, CarbonInterface $asOf): float
    {
        $config = $this->resolver->config(StatutoryRuleType::ProvidentFund, $asOf);
        $wage = min(max($pfWage, 0), (float) $config['wage_ceiling']);

        return round($wage * (float) $config['eps_rate']);
    }

    /**
     * Employee ESI contribution, only when gross is within the coverage ceiling.
     * Rounded up to the next rupee per ESIC rules.
     */
    public function esiEmployee(float $grossWage, CarbonInterface $asOf): float
    {
        return $this->esiContribution($grossWage, $asOf, 'employee_rate');
    }

    /** Employer ESI contribution, within the coverage ceiling. */
    public function esiEmployer(float $grossWage, CarbonInterface $asOf): float
    {
        return $this->esiContribution($grossWage, $asOf, 'employer_rate');
    }

    private function esiContribution(float $grossWage, CarbonInterface $asOf, string $rateKey): float
    {
        $config = $this->resolver->config(StatutoryRuleType::EmployeeStateInsurance, $asOf);

        if ($grossWage <= 0 || $grossWage > (float) $config['wage_ceiling']) {
            return 0.0;
        }

        return (float) ceil($grossWage * (float) $config[$rateKey]);
    }

    /**
     * Professional Tax for the employee's state.
     *
     * PT is a state levy, so $jurisdiction selects the slab set; a state with no
     * configured rule raises rather than defaulting to another state's rates.
     * Month overrides are per-slab — Maharashtra's February top-up applies to the
     * top slab only, so a mid-slab earner is unaffected.
     */
    public function professionalTax(
        float $monthlyGross,
        CarbonInterface $asOf,
        ?string $jurisdiction,
        bool $isFemale = false,
    ): float {
        if ($jurisdiction === null) {
            throw new \DomainException(
                'Professional Tax cannot be computed: no work state is set on the '
                .'employee\'s office or the company default. Set a state before running payroll.',
            );
        }

        $config = $this->resolver->config(StatutoryRuleType::ProfessionalTax, $asOf, $jurisdiction);

        $exemption = $config['female_exemption_up_to'] ?? null;
        if ($isFemale && $exemption !== null && $monthlyGross <= (float) $exemption) {
            return 0.0;
        }

        foreach ($config['slabs'] as $slab) {
            $upTo = $slab['up_to'] ?? null;

            if ($upTo !== null && $monthlyGross > (float) $upTo) {
                continue;
            }

            $override = $slab['month_overrides'][(string) $asOf->month] ?? null;

            return (float) ($override ?? $slab['amount']);
        }

        return 0.0;
    }

    /**
     * Annual income tax for a regime: slab tax on income after the standard
     * deduction, the section 87A rebate, plus health & education cess.
     */
    public function incomeTax(float $annualGross, CarbonInterface $asOf, string $regime = 'new'): float
    {
        $config = $this->resolver->config(StatutoryRuleType::IncomeTax, $asOf, null, $regime);

        $taxable = max(0.0, $annualGross - (float) $config['standard_deduction']);

        if ($taxable <= (float) $config['rebate_87a_limit']) {
            return 0.0;
        }

        $tax = 0.0;
        $lower = 0.0;

        foreach ($config['slabs'] as $slab) {
            if ($taxable <= $lower) {
                break;
            }

            $upper = isset($slab['up_to']) ? (float) $slab['up_to'] : PHP_FLOAT_MAX;
            $tax += (min($taxable, $upper) - $lower) * (float) $slab['rate'];
            $lower = $upper;
        }

        return round($tax * (1 + (float) $config['cess_rate']));
    }

    /**
     * Estimated monthly TDS: project the current monthly gross across 12 months,
     * compute the annual liability, and spread it evenly. An estimate refined each
     * cycle; final liability is reconciled at year end.
     */
    public function monthlyTds(float $monthlyGross, CarbonInterface $asOf, string $regime = 'new'): float
    {
        return round($this->incomeTax($monthlyGross * 12, $asOf, $regime) / 12);
    }
}
