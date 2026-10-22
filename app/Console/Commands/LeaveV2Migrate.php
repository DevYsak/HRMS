<?php

namespace App\Console\Commands;

use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveV2MigrationService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Leave V2 balance migration: each employee's CURRENT CSL available becomes
 * their Earned Leave opening — never Casual + Sick + Annual added together,
 * never a fresh 12 days. See LeaveV2MigrationService for the rules.
 *
 * --preview (the default) runs inside a transaction that is always rolled
 * back: it writes nothing. --apply migrates every READY employee, each in
 * their own transaction, verified (ledger reconciles) before it commits;
 * STOP rows are left alone and MIGRATED rows are skipped, so re-running is
 * safe. Exit code 0 only when nothing is STOP and nothing failed.
 */
#[Signature('leave:v2-migrate
    {--preview : Show what would happen and write nothing (default)}
    {--apply : Migrate every READY employee}
    {--actor= : Email of the HR / admin user recorded on every entry (required with --apply)}
    {--employee=* : Limit to these employee codes}
    {--source=CSL : Code of the leave type whose current balance becomes Earned Leave}
    {--overrides= : CSV of HR overrides: employee_code,earned_available,reason}
    {--entitlement=12 : The Earned Leave annual entitlement every policy must give}
    {--year= : Leave year label, e.g. 2026/27 (defaults to the current leave year)}
    {--csv= : Also write the rows to this CSV path}')]
#[Description('Audited, idempotent Leave V2 migration: current CSL available → Earned Leave opening (preview writes nothing)')]
class LeaveV2Migrate extends Command
{
    public function handle(LeaveV2MigrationService $migration, LeaveYearResolver $years): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('preview')) {
            $this->error('Choose --preview or --apply, not both.');

            return self::FAILURE;
        }

        $entitlement = (float) $this->option('entitlement');

        try {
            $overrides = $this->option('overrides') ? $this->readOverrides((string) $this->option('overrides')) : [];
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $actor = null;
        if ($apply) {
            $actor = User::where('email', (string) $this->option('actor'))->first();
            if (! $actor) {
                $this->error('--apply needs --actor=<email> of an existing HR / admin user.');

                return self::FAILURE;
            }
            if (! $actor->hasPermission('manage_leave_balances') && ! $actor->isSuperAdmin()) {
                $this->error("{$actor->email} may not manage leave balances.");

                return self::FAILURE;
            }
        }

        if (! $apply) {
            // Read only: anything the lookups might create (a leave year) is rolled back too.
            DB::beginTransaction();
            try {
                [$year, $rows] = $this->plan($migration, $years, $overrides, $entitlement);
                if ($year === null) {
                    return self::FAILURE;
                }
                $this->report($rows, $year, 'PREVIEW — nothing is written');
            } finally {
                DB::rollBack();
            }

            return $rows->contains('status', LeaveV2MigrationService::STOP) ? self::FAILURE : self::SUCCESS;
        }

        [$year, $rows] = $this->plan($migration, $years, $overrides, $entitlement);
        if ($year === null) {
            return self::FAILURE;
        }

        $failed = 0;
        $rows = $rows->map(function (array $row) use ($migration, $year, $actor, $entitlement, &$failed) {
            if ($row['status'] !== LeaveV2MigrationService::READY) {
                return $row;
            }
            try {
                return $migration->apply($row, $year, $actor, $entitlement);
            } catch (Throwable $e) {
                $failed++;

                return ['status' => 'FAILED', 'reason' => 'Rolled back: '.$e->getMessage()] + $row;
            }
        });

        $this->report($rows, $year, 'APPLIED by '.$actor->email);

        return $failed === 0 && ! $rows->contains('status', LeaveV2MigrationService::STOP) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, array{available: float, reason: string}>  $overrides
     * @return array{0: ?LeaveYear, 1: Collection<int, array<string, mixed>>}
     */
    private function plan(LeaveV2MigrationService $migration, LeaveYearResolver $years, array $overrides, float $entitlement): array
    {
        $year = $this->option('year') ? LeaveYear::where('label', $this->option('year'))->first() : $years->current();
        if (! $year) {
            $this->error('No leave year '.$this->option('year').'.');

            return [null, collect()];
        }

        $source = $migration->sourceType((string) $this->option('source'));
        if (! $source) {
            $this->warn("No leave type with code '{$this->option('source')}' — every employee without an HR override is STOP.");
        }

        return [$year, $migration->plan($year, $source, $this->option('employee'), $overrides, $entitlement)];
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function report(Collection $rows, LeaveYear $year, string $heading): void
    {
        $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

        $this->line("<comment>Leave V2 migration · {$year->label} · {$heading}</comment>");
        $this->table(
            ['Employee', 'Code', 'Policy', 'CSL avail.', 'Sick', 'Annual (hist.)', 'Comp Off', 'Special', 'Pending', 'Proposed Earned', 'Entitlement', 'Diff', 'Status', 'Reason'],
            $rows->map(fn (array $r) => [
                $r['employee'], $r['code'], $r['policy'] ?? '—', $fmt($r['csl_available']), $fmt($r['sick_available']), $fmt($r['annual_historical']),
                $fmt($r['comp_off']), $fmt($r['special']), $r['pending_legacy'], $fmt($r['proposed']), $fmt($r['entitlement']), $fmt($r['difference']),
                $r['status'], $r['reason'],
            ])->all(),
        );

        $counts = $rows->countBy('status')->map(fn ($n, $s) => "{$s} {$n}")->implode(' · ');
        $this->line('  '.$rows->count().' employee(s): '.($counts ?: 'none'));
        $this->line('  Sick, Annual and other historical balances are shown only — never added. Comp Off and Special Leave stay separate.');

        if ($path = $this->option('csv')) {
            $out = fopen($path, 'w');
            fputcsv($out, ['employee', 'code', 'policy', 'csl_available', 'sick_available', 'annual_historical', 'comp_off', 'special', 'pending_legacy', 'proposed_earned', 'entitlement', 'difference', 'status', 'reason']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['employee'], $r['code'], $r['policy'], $r['csl_available'], $r['sick_available'], $r['annual_historical'], $r['comp_off'], $r['special'], $r['pending_legacy'], $r['proposed'], $r['entitlement'], $r['difference'], $r['status'], $r['reason']]);
            }
            fclose($out);
            $this->line("  Rows written to {$path}");
        }
    }

    /**
     * HR overrides: employee_code,earned_available,reason — every row needs a
     * non-negative figure and a reason.
     *
     * @return array<string, array{available: float, reason: string}>
     */
    private function readOverrides(string $path): array
    {
        if (! is_file($path) || ! ($handle = fopen($path, 'r'))) {
            throw new \InvalidArgumentException("Cannot read overrides file {$path}.");
        }

        $overrides = [];
        $header = fgetcsv($handle);
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || $cells === []) {
                continue;
            }
            [$code, $available, $reason] = array_pad(array_map('trim', $cells), 3, '');
            if ($code === '' || ! is_numeric($available) || (float) $available < 0 || $reason === '') {
                fclose($handle);
                throw new \InvalidArgumentException("Overrides line {$line}: needs employee_code, a non-negative earned_available and a reason.");
            }
            $overrides[$code] = ['available' => round((float) $available, 2), 'reason' => $reason];
        }
        fclose($handle);

        return $overrides;
    }
}
