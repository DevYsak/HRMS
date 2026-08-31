<?php

use App\Models\LeavePolicy;
use Illuminate\Support\Facades\DB;

/**
 * Rolling back allow_unlimited_carry_over_on_leave_policies must not turn
 * "unlimited" into "no carry-forward".
 *
 * The old column is NOT NULL, so unlimited has no direct representation there.
 * A naive down() writes 0 over it — and 0 already means "nothing may be
 * carried forward", the exact opposite instruction, applied silently to every
 * policy HR had set to unlimited.
 *
 * These drive the migration's real park/restore steps, which is where the
 * information can be lost. They deliberately do not run the ALTER: MySQL
 * commits implicitly on DDL, which dissolves the transaction each test is
 * wrapped in and leaks its rows into every test that follows.
 */
function ucoMigration(): object
{
    return require database_path(
        'migrations/2026_08_27_223351_allow_unlimited_carry_over_on_leave_policies.php'
    );
}

function ucoPolicy(string $name, ?float $cap): LeavePolicy
{
    $policy = LeavePolicy::create([
        'name' => $name,
        'statutory_weeks' => 5.60,
        'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_ADDITIONAL,
        'is_active' => true,
    ]);

    // Through the query builder so a null is written as a null.
    DB::table('leave_policies')->where('id', $policy->id)->update(['max_carry_over_days' => $cap]);

    return $policy->fresh();
}

function ucoCap(LeavePolicy $policy): ?string
{
    $value = DB::table('leave_policies')->where('id', $policy->id)->value('max_carry_over_days');

    return $value === null ? null : (string) $value;
}

test('rolling back does not write no-carry-forward over an unlimited policy', function () {
    $unlimited = ucoPolicy('UCO Unlimited', null);
    $noCarry = ucoPolicy('UCO No Carry', 0);
    $capped = ucoPolicy('UCO Capped', 5);

    ucoMigration()::parkUnlimited();

    // The substitution this exists to prevent: unlimited must not read as 0.
    expect(ucoCap($unlimited))->not->toBe('0.00')
        ->and(ucoCap($noCarry))->toBe('0.00')
        ->and(ucoCap($capped))->toBe('5.00');
});

test('re-applying restores unlimited exactly where it was, and nowhere else', function () {
    $unlimited = ucoPolicy('UCO Unlimited', null);
    $noCarry = ucoPolicy('UCO No Carry', 0);
    $capped = ucoPolicy('UCO Capped', 5);

    $m = ucoMigration();
    $m::parkUnlimited();
    $m::restoreUnlimited();

    expect(ucoCap($unlimited))->toBeNull()
        // A policy meaning "no carry-forward" must not come back unlimited.
        ->and(ucoCap($noCarry))->toBe('0.00')
        ->and(ucoCap($capped))->toBe('5.00');
});

test('a policy an admin set to unlimited after deployment survives the round trip', function () {
    // The case a naive down() loses outright: unlimited chosen after the
    // migration first ran, so it appears nowhere in the migration's defaults.
    $adminChoice = ucoPolicy('UCO Admin Chose Unlimited', null);

    $m = ucoMigration();
    $m::parkUnlimited();
    $m::restoreUnlimited();

    expect(ucoCap($adminChoice))->toBeNull();
});

test('two round trips do not drift', function () {
    $unlimited = ucoPolicy('UCO Unlimited', null);
    $noCarry = ucoPolicy('UCO No Carry', 0);

    $m = ucoMigration();
    $m::parkUnlimited();
    $m::restoreUnlimited();
    $m::parkUnlimited();
    $m::restoreUnlimited();

    expect(ucoCap($unlimited))->toBeNull()
        ->and(ucoCap($noCarry))->toBe('0.00');
});

test('the parked marker can never be read as a real cap', function () {
    $unlimited = ucoPolicy('UCO Unlimited', null);

    ucoMigration()::parkUnlimited();

    // A cap is a number of days, so a negative marker is unmistakable.
    expect((float) ucoCap($unlimited))->toBeLessThan(0);
});

test('restore reports whether anything was parked, which is what gates the re-seed', function () {
    $m = ucoMigration();

    // Nothing unlimited: a first application, so up() applies the approved
    // decision rather than a restore.
    ucoPolicy('UCO No Carry', 0);
    expect($m::restoreUnlimited())->toBe(0);

    // Something parked: a re-apply, so up() must leave the seeding alone.
    // Counted against what is actually unlimited, which includes the UK
    // Standard policy the migrations themselves set.
    ucoPolicy('UCO Unlimited', null);
    $expected = DB::table('leave_policies')->whereNull('max_carry_over_days')->count();
    expect($expected)->toBeGreaterThan(0);

    $m::parkUnlimited();
    expect($m::restoreUnlimited())->toBe($expected);
});

test('down parks before the column stops accepting null, and up restores before re-seeding', function () {
    // Order is the whole point: parking after the ALTER would be too late, and
    // re-seeding before the restore would overwrite what was parked.
    $source = file_get_contents(database_path(
        'migrations/2026_08_27_223351_allow_unlimited_carry_over_on_leave_policies.php'
    ));

    $down = substr($source, strpos($source, 'public function down'));
    expect(strpos($down, 'parkUnlimited'))->toBeLessThan(strpos($down, 'NOT NULL'));

    $up = substr($source, strpos($source, 'public function up'), strpos($source, 'public function down') - strpos($source, 'public function up'));
    expect(strpos($up, 'restoreUnlimited'))->toBeLessThan(strpos($up, "where('name', 'UK Standard')"));
});
