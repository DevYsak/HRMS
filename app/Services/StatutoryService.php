<?php

namespace App\Services;

/**
 * Statutory Indian payroll calculations (EPF, ESI, Professional Tax, TDS).
 *
 * All rates and slabs are exposed as constants so they can be updated in one
 * place when the government revises them. Every method is pure (no DB / no
 * side effects) so it can be unit-tested in isolation and reused by the
 * salary engine, previews, and reports.
 *
 * Rate basis (as of FY 2025-26 / AY 2026-27):
 *  - EPF: employee 12% of (Basic + DA), statutory wage ceiling ₹15,000 → max ₹1,800.
 *  - ESI: employee 0.75% + employer 3.25% of gross, only when gross ≤ ₹21,000.
 *  - Professional Tax (Maharashtra): ₹200/month, ₹300 in February; slab-based.
 *  - Income Tax (New Regime): standard deduction ₹75,000, 87A rebate up to ₹12L,
 *    4% health & education cess. Monthly TDS = projected annual tax / 12.
 */
class StatutoryService
{
    // ── EPF ──────────────────────────────────────────────────────────────────
    public const PF_RATE = 0.12;

    public const PF_WAGE_CEILING = 15000.0;

    public const EPS_RATE = 0.0833; // employer share diverted to pension (on ceiling)

    // ── ESI ──────────────────────────────────────────────────────────────────
    public const ESI_EMPLOYEE_RATE = 0.0075;

    public const ESI_EMPLOYER_RATE = 0.0325;

    public const ESI_WAGE_CEILING = 21000.0;

    // ── Income Tax (New Regime, FY 2025-26) ─────────────────────────────────
    public const STANDARD_DEDUCTION = 75000.0;

    public const REBATE_87A_LIMIT = 1200000.0; // taxable income up to this → nil tax

    public const HEALTH_EDU_CESS = 0.04;

    /** @var array<int, array{0: float, 1: float}> [upper_bound, rate] ascending; PHP_INT_MAX for top slab */
    public const NEW_REGIME_SLABS = [
        [400000.0, 0.00],
        [800000.0, 0.05],
        [1200000.0, 0.10],
        [1600000.0, 0.15],
        [2000000.0, 0.20],
        [2400000.0, 0.25],
        [PHP_FLOAT_MAX, 0.30],
    ];

    /**
     * Employee EPF contribution: 12% of PF wage (Basic + DA), capped at the
     * ₹15,000 wage ceiling → maximum ₹1,800/month.
     */
    public function providentFundEmployee(float $pfWage): float
    {
        $wage = min(max($pfWage, 0), self::PF_WAGE_CEILING);

        return round($wage * self::PF_RATE);
    }

    /**
     * Employer EPF contribution (matches employee 12%). Informational only —
     * not deducted from the employee's net pay.
     */
    public function providentFundEmployer(float $pfWage): float
    {
        return $this->providentFundEmployee($pfWage);
    }

    /**
     * Employer share diverted to the Employees' Pension Scheme (8.33% of the
     * capped wage). Part of the employer 12%, shown for transparency.
     */
    public function pensionSchemeEmployer(float $pfWage): float
    {
        $wage = min(max($pfWage, 0), self::PF_WAGE_CEILING);

        return round($wage * self::EPS_RATE);
    }

    /**
     * Employee ESI contribution: 0.75% of gross, only when gross wage is within
     * the ₹21,000 coverage ceiling. Rounded up to the next rupee per ESIC rules.
     */
    public function esiEmployee(float $grossWage): float
    {
        if ($grossWage <= 0 || $grossWage > self::ESI_WAGE_CEILING) {
            return 0.0;
        }

        return (float) ceil($grossWage * self::ESI_EMPLOYEE_RATE);
    }

    /**
     * Employer ESI contribution: 3.25% of gross, within the coverage ceiling.
     */
    public function esiEmployer(float $grossWage): float
    {
        if ($grossWage <= 0 || $grossWage > self::ESI_WAGE_CEILING) {
            return 0.0;
        }

        return (float) ceil($grossWage * self::ESI_EMPLOYER_RATE);
    }

    /**
     * Professional Tax — Maharashtra slab.
     *
     *  - Monthly gross ≤ ₹7,500 → nil
     *  - ₹7,501 – ₹10,000     → ₹175
     *  - > ₹10,000            → ₹200 (₹300 in February to make the ₹2,500 annual cap)
     *  - Women earning ≤ ₹25,000/month are exempt.
     *
     * @param  int  $month  1-12 (February = 2 triggers the ₹300 rate)
     */
    public function professionalTaxMaharashtra(float $monthlyGross, int $month, bool $isFemale = false): float
    {
        if ($isFemale && $monthlyGross <= 25000) {
            return 0.0;
        }

        if ($monthlyGross <= 7500) {
            return 0.0;
        }

        if ($monthlyGross <= 10000) {
            return 175.0;
        }

        return $month === 2 ? 300.0 : 200.0;
    }

    /**
     * Annual income tax under the New Regime (FY 2025-26): slab tax on income
     * after the standard deduction, the section 87A rebate (nil tax up to
     * ₹12L taxable), plus 4% health & education cess.
     */
    public function incomeTaxNewRegime(float $annualGross): float
    {
        $taxable = max(0.0, $annualGross - self::STANDARD_DEDUCTION);

        if ($taxable <= self::REBATE_87A_LIMIT) {
            return 0.0;
        }

        $tax = 0.0;
        $lower = 0.0;
        foreach (self::NEW_REGIME_SLABS as [$upper, $rate]) {
            if ($taxable > $lower) {
                $slabAmount = min($taxable, $upper) - $lower;
                $tax += $slabAmount * $rate;
                $lower = $upper;
            } else {
                break;
            }
        }

        return round($tax * (1 + self::HEALTH_EDU_CESS));
    }

    /**
     * Estimated monthly TDS: project the current monthly gross across 12 months,
     * compute the annual New-Regime tax, and spread it evenly. This is an
     * estimate refined each cycle; final liability is reconciled at year end.
     */
    public function monthlyTds(float $monthlyGross): float
    {
        $annualTax = $this->incomeTaxNewRegime($monthlyGross * 12);

        return round($annualTax / 12);
    }
}
