<?php

use App\Enums\StatutoryRuleType;
use App\Models\StatutoryRule;
use App\Services\Statutory\MissingStatutoryRuleException;
use App\Services\StatutoryService;
use Database\Seeders\StatutoryRuleSeeder;
use Illuminate\Support\Carbon;

/**
 * Behaviour-preservation suite for the DB-driven StatutoryService.
 *
 * The numbers asserted here are identical to those the old constant-based service
 * produced — seeding statutory_rules was a refactor, not a rate change, and these
 * lock that in. A date inside the seeded effective windows is used throughout.
 */
beforeEach(function () {
    $this->seed(StatutoryRuleSeeder::class);
    $this->svc = app(StatutoryService::class);
    $this->asOf = Carbon::parse('2026-06-30');
});

// ── EPF ──────────────────────────────────────────────────────────────────────
test('employee PF is 12% of basic capped at the 15k wage ceiling', function () {
    expect($this->svc->providentFundEmployee(30000, $this->asOf))->toBe(1800.0)  // capped
        ->and($this->svc->providentFundEmployee(15000, $this->asOf))->toBe(1800.0)  // at ceiling
        ->and($this->svc->providentFundEmployee(12000, $this->asOf))->toBe(1440.0)  // below ceiling
        ->and($this->svc->providentFundEmployee(0, $this->asOf))->toBe(0.0);
});

test('employer pension share is 8.33% of the capped wage', function () {
    expect($this->svc->pensionSchemeEmployer(30000, $this->asOf))->toBe(1250.0) // 15000 * 8.33%
        ->and($this->svc->pensionSchemeEmployer(10000, $this->asOf))->toBe(833.0);
});

// ── ESI ──────────────────────────────────────────────────────────────────────
test('ESI applies only within the 21k gross ceiling', function () {
    expect($this->svc->esiEmployee(20000, $this->asOf))->toBe(150.0)   // 0.75%
        ->and($this->svc->esiEmployee(21000, $this->asOf))->toBe(158.0) // ceil(157.5)
        ->and($this->svc->esiEmployee(21001, $this->asOf))->toBe(0.0)   // above ceiling
        ->and($this->svc->esiEmployer(20000, $this->asOf))->toBe(650.0) // 3.25%
        ->and($this->svc->esiEmployer(25000, $this->asOf))->toBe(0.0);
});

// ── Professional Tax (Maharashtra) ───────────────────────────────────────────
test('Maharashtra professional tax follows the monthly slab with the Feb bump', function () {
    expect($this->svc->professionalTax(30000, $this->asOf, 'MH'))->toBe(200.0)                       // normal month
        ->and($this->svc->professionalTax(30000, Carbon::parse('2026-02-28'), 'MH'))->toBe(300.0)    // February
        ->and($this->svc->professionalTax(9000, $this->asOf, 'MH'))->toBe(175.0)                     // mid slab
        ->and($this->svc->professionalTax(7000, $this->asOf, 'MH'))->toBe(0.0);                      // below threshold
});

test('the February top-up applies only to the top slab', function () {
    // A mid-slab earner must still pay the flat mid-slab amount in February.
    expect($this->svc->professionalTax(9000, Carbon::parse('2026-02-28'), 'MH'))->toBe(175.0);
});

test('women earning up to 25k are exempt from Maharashtra PT', function () {
    expect($this->svc->professionalTax(20000, Carbon::parse('2026-02-28'), 'MH', true))->toBe(0.0)
        ->and($this->svc->professionalTax(30000, Carbon::parse('2026-02-28'), 'MH', true))->toBe(300.0);
});

// ── Income Tax (New Regime FY 2025-26) ───────────────────────────────────────
test('new regime gives nil tax up to 12L taxable via 87A rebate', function () {
    expect($this->svc->incomeTax(1200000, $this->asOf))->toBe(0.0)
        ->and($this->svc->incomeTax(1275000, $this->asOf))->toBe(0.0); // taxable exactly 12L
});

test('new regime slab tax with cess is computed correctly above the rebate', function () {
    // 15L gross → 14.25L taxable → 20000 + 40000 + 33750 = 93750 → *1.04 cess = 97500
    expect($this->svc->incomeTax(1500000, $this->asOf))->toBe(97500.0);
});

test('monthly TDS spreads projected annual tax across 12 months', function () {
    expect($this->svc->monthlyTds(125000, $this->asOf))->toBe(8125.0) // 15L annual → 97500 / 12
        ->and($this->svc->monthlyTds(50000, $this->asOf))->toBe(0.0);  // within rebate
});

// ── Fail-loud contract ───────────────────────────────────────────────────────
test('an unconfigured period raises rather than silently defaulting', function () {
    // Before any seeded rule came into force.
    expect(fn () => $this->svc->providentFundEmployee(30000, Carbon::parse('2000-01-01')))
        ->toThrow(MissingStatutoryRuleException::class);
});

test('an unconfigured PT state raises rather than applying another state', function () {
    expect(fn () => $this->svc->professionalTax(30000, $this->asOf, 'KA'))
        ->toThrow(MissingStatutoryRuleException::class);
});

test('a closed rule stops applying after its effective_to', function () {
    // Close PF the day before asOf and confirm the resolver no longer finds it.
    StatutoryRule::where('type', StatutoryRuleType::ProvidentFund->value)
        ->update(['effective_to' => $this->asOf->copy()->subDay()]);

    expect(fn () => $this->svc->providentFundEmployee(30000, $this->asOf))
        ->toThrow(MissingStatutoryRuleException::class);
});
