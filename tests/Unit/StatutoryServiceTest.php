<?php

use App\Services\StatutoryService;

beforeEach(function () {
    $this->svc = new StatutoryService;
});

// ── EPF ──────────────────────────────────────────────────────────────────────
test('employee PF is 12% of basic capped at the 15k wage ceiling', function () {
    expect($this->svc->providentFundEmployee(30000))->toBe(1800.0)  // capped
        ->and($this->svc->providentFundEmployee(15000))->toBe(1800.0)  // at ceiling
        ->and($this->svc->providentFundEmployee(12000))->toBe(1440.0)  // below ceiling → actual
        ->and($this->svc->providentFundEmployee(0))->toBe(0.0);
});

test('employer pension share is 8.33% of the capped wage', function () {
    expect($this->svc->pensionSchemeEmployer(30000))->toBe(1250.0) // 15000 * 8.33%
        ->and($this->svc->pensionSchemeEmployer(10000))->toBe(833.0);
});

// ── ESI ──────────────────────────────────────────────────────────────────────
test('ESI applies only within the 21k gross ceiling', function () {
    expect($this->svc->esiEmployee(20000))->toBe(150.0)   // 0.75%
        ->and($this->svc->esiEmployee(21000))->toBe(158.0) // ceil(157.5)
        ->and($this->svc->esiEmployee(21001))->toBe(0.0)   // above ceiling → exempt
        ->and($this->svc->esiEmployer(20000))->toBe(650.0) // 3.25%
        ->and($this->svc->esiEmployer(25000))->toBe(0.0);
});

// ── Professional Tax (Maharashtra) ───────────────────────────────────────────
test('Maharashtra professional tax follows the monthly slab with the Feb bump', function () {
    expect($this->svc->professionalTaxMaharashtra(30000, 6))->toBe(200.0)   // normal month
        ->and($this->svc->professionalTaxMaharashtra(30000, 2))->toBe(300.0) // February
        ->and($this->svc->professionalTaxMaharashtra(9000, 6))->toBe(175.0)  // mid slab
        ->and($this->svc->professionalTaxMaharashtra(7000, 6))->toBe(0.0);   // below threshold
});

test('women earning up to 25k are exempt from Maharashtra PT', function () {
    expect($this->svc->professionalTaxMaharashtra(20000, 2, true))->toBe(0.0)     // exempt
        ->and($this->svc->professionalTaxMaharashtra(30000, 2, true))->toBe(300.0); // above exemption
});

// ── Income Tax (New Regime FY 2025-26) ───────────────────────────────────────
test('new regime gives nil tax up to 12L taxable via 87A rebate', function () {
    expect($this->svc->incomeTaxNewRegime(1200000))->toBe(0.0)  // taxable 1.125L < 12L
        ->and($this->svc->incomeTaxNewRegime(1275000))->toBe(0.0); // taxable exactly 12L
});

test('new regime slab tax with cess is computed correctly above the rebate', function () {
    // 15L gross → 14.25L taxable → 20000 + 40000 + 33750 = 93750 → *1.04 cess = 97500
    expect($this->svc->incomeTaxNewRegime(1500000))->toBe(97500.0);
});

test('monthly TDS spreads projected annual tax across 12 months', function () {
    expect($this->svc->monthlyTds(125000))->toBe(8125.0) // 15L annual → 97500 / 12
        ->and($this->svc->monthlyTds(50000))->toBe(0.0);  // 6L annual → within rebate
});
