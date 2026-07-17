<?php

namespace App\Enums;

/**
 * The statutory rule families the payroll engine resolves from the database.
 *
 * Each case documents the `config` JSON shape its rows must carry; the resolver
 * validates against these keys before the engine consumes a rule, so a
 * malformed row surfaces as a configuration error rather than a wrong payslip.
 */
enum StatutoryRuleType: string
{
    /**
     * Employees' Provident Fund.
     * config: { rate: float, wage_ceiling: float, eps_rate: float }
     */
    case ProvidentFund = 'pf';

    /**
     * Employees' State Insurance.
     * config: { employee_rate: float, employer_rate: float, wage_ceiling: float }
     */
    case EmployeeStateInsurance = 'esi';

    /**
     * Professional Tax — levied per state, so rows are jurisdiction-scoped.
     * config: {
     *   slabs: [{
     *     up_to: float|null,                        // ascending; null = no upper bound
     *     amount: float,
     *     month_overrides?: { "<month 1-12>": float }  // per-slab, e.g. Maharashtra's
     *   }],                                            // February top-up on the top slab only
     *   female_exemption_up_to: float|null
     * }
     */
    case ProfessionalTax = 'professional_tax';

    /**
     * Income Tax — regime-scoped ('new' / 'old').
     * config: {
     *   standard_deduction: float,
     *   rebate_87a_limit: float,
     *   cess_rate: float,
     *   slabs: [{ up_to: float|null, rate: float }]     // ascending; up_to null = top slab
     * }
     */
    case IncomeTax = 'income_tax';

    public function label(): string
    {
        return match ($this) {
            self::ProvidentFund => 'Provident Fund (EPF)',
            self::EmployeeStateInsurance => 'Employees\' State Insurance (ESI)',
            self::ProfessionalTax => 'Professional Tax',
            self::IncomeTax => 'Income Tax (TDS)',
        };
    }

    /** Config keys every row of this type must define. */
    public function requiredConfigKeys(): array
    {
        return match ($this) {
            self::ProvidentFund => ['rate', 'wage_ceiling', 'eps_rate'],
            self::EmployeeStateInsurance => ['employee_rate', 'employer_rate', 'wage_ceiling'],
            self::ProfessionalTax => ['slabs'],
            self::IncomeTax => ['standard_deduction', 'rebate_87a_limit', 'cess_rate', 'slabs'],
        };
    }

    /** Whether rows of this type are scoped to a state. */
    public function isJurisdictional(): bool
    {
        return $this === self::ProfessionalTax;
    }

    /** Whether rows of this type are scoped to a tax regime. */
    public function isRegimeScoped(): bool
    {
        return $this === self::IncomeTax;
    }
}
