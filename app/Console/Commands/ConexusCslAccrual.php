<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Leave\ConexusCslAccrualService;
use App\Services\Leave\ConexusLeavePolicyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Casual / Sick Leave earned for completed months: 1 day each, effective the
 * month's last day, at most 12 in a July–June leave year.
 *
 * Without --apply the run is simulated inside one database transaction and
 * rolled back: the tables show the plan per employee (what is already
 * credited, which months the register credit stands for, which are missing)
 * and the exact result --apply would leave. Nothing is saved.
 *
 * Scheduled for 00:15 on the 1st — before the 1 July rollover, so June's day
 * is in the finishing year's balance when it is carried forward. Re-running
 * posts nothing new. Exit code 0 only when every employee PASSes and every
 * policy check holds.
 */
#[Signature('leave:conexus-csl-accrual
    {--apply : Post the missing month credits (otherwise a rolled-back preview)}
    {--as-of= : Run as if on this date (default today); months that ended before it are earned}
    {--actor= : Email of the HR/admin user recorded on every ledger entry}
    {--employee=* : Limit to these login emails}')]
#[Description('Credit Casual / Sick Leave: 1 day per completed month of the leave year')]
class ConexusCslAccrual extends Command
{
    public function handle(ConexusCslAccrualService $accrual, ConexusLeavePolicyService $policy): int
    {
        $apply = (bool) $this->option('apply');
        $csl = $policy->cslType();

        if ($csl === null) {
            $this->error('There is no Casual / Sick Leave (CSL) type yet. Run leave:conexus-reconcile first.');

            return self::FAILURE;
        }

        try {
            $asOf = $this->option('as-of') ? Carbon::parse($this->option('as-of'))->startOfDay() : Carbon::today();
        } catch (Throwable) {
            $this->error('--as-of must be a date (YYYY-MM-DD).');

            return self::FAILURE;
        }

        $actor = $this->option('actor') ? User::where('email', $this->option('actor'))->first() : null;
        if ($this->option('actor') && $actor === null) {
            $this->error('No user with email '.$this->option('actor').'.');

            return self::FAILURE;
        }

        $this->info(($apply ? 'APPLYING' : 'PREVIEW (nothing will be saved)')." — CSL accrual as of {$asOf->toDateString()}");

        if (! $apply) {
            DB::beginTransaction();
        }

        try {
            $run = $accrual->run($csl, $asOf, true, $actor, $this->option('employee'));
            $passed = self::render($this, $run);
        } catch (Throwable $e) {
            if (! $apply) {
                DB::rollBack();
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $apply) {
            DB::rollBack();
            $this->newLine();
            $this->warn('Preview only — every credit above was simulated in a transaction and rolled back. Re-run with --apply to save.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Print the plan, the resulting balances and the policy checks. Shared
     * with leave:conexus-reconcile.
     *
     * @param  array{year: mixed, plans: array<int, array<string, mixed>>, results: array<int, array<string, mixed>>, checks: array<int, array{0: string, 1: bool, 2: string}>}  $run
     */
    public static function render(Command $out, array $run): bool
    {
        $year = $run['year'];
        $months = fn (array $list) => $list === [] ? '—' : collect($list)->map(fn ($m) => Carbon::parse($m.'-01')->format('M'))->implode(', ');

        $out->newLine();
        $out->info("CSL accrual plan — {$year->label} ({$year->starts_on->toDateString()} – {$year->ends_on->toDateString()})");
        $out->table(
            ['Employee', 'CSL type', 'Credit before', 'Carry', 'Used', 'Encashed', 'Pending', 'Earned months', 'Register covers', 'Missing → credit', 'Duplicates', 'Legacy AL', 'Approved after', 'Available after', 'Plan'],
            collect($run['plans'])->map(function (array $p, int $i) use ($run, $months) {
                $s = $p['summary'];
                $r = $run['results'][$i];

                return [
                    $p['name'], '#'.$p['csl_type_id'],
                    round($s['base'] + $s['accrued'], 2), $s['carry_forward'], $s['used'], $s['encashed'], $s['pending'],
                    count($p['expected_months']).' ('.$months($p['expected_months']).')',
                    $months($p['base_months']),
                    $months($p['missing_months']),
                    count($p['duplicates']),
                    $p['legacy_annual'],
                    $r['approved'], $r['available'],
                    $p['status'],
                ];
            })->all(),
        );

        foreach ($run['plans'] as $p) {
            if ($p['status'] !== ConexusCslAccrualService::PASS) {
                $out->error("  ✗ {$p['name']}: {$p['reason']}");
            }
        }

        $out->newLine();
        $out->info('Result per employee');
        $out->table(
            ['Name', 'Current-year accrued', 'Carry', 'Used', 'Encashed', 'Pending', 'Approved balance', 'Available to request', 'Expected months', 'Result'],
            collect($run['results'])->map(fn (array $r) => [
                $r['name'], $r['current_year'], $r['carry'], $r['used'], $r['encashed'], $r['pending'],
                $r['approved'], $r['available'], $r['expected_months'], $r['status'],
            ])->all(),
        );

        foreach ($run['results'] as $r) {
            if ($r['status'] !== ConexusCslAccrualService::PASS) {
                $out->error("  ✗ {$r['name']}: {$r['note']}");
            }
        }

        $out->newLine();
        $out->info('Checks');
        foreach ($run['checks'] as [$check, $ok, $detail]) {
            $ok ? $out->line("  ✓ {$check} — {$detail}") : $out->error("  ✗ {$check} — {$detail}");
        }

        $results = collect($run['results']);
        $passed = $results->where('status', ConexusCslAccrualService::PASS)->count();
        $out->newLine();
        $out->line(sprintf('%d PASS, %d FAIL.', $passed, $results->count() - $passed));

        return $passed === $results->count() && collect($run['checks'])->every(fn ($c) => $c[1]);
    }
}
