<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Leave\LeaveHrHistoryReconciliationService as History;
use App\Services\Leave\LeaveRegisterReconciliationService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compares the HR register (the authoritative snapshot up to the cutoff) plus
 * everything the portal did after it with today's Earned Leave balance. See
 * LeaveHrHistoryReconciliationService for the formula.
 *
 * The preview (default) runs inside a database transaction that is always
 * rolled back, so not even a helper can leave a row behind. --apply restates
 * exactly ONE employee (--employee, --actor): only a MATCH with a supported
 * ledger shape, the total available unchanged, audited — never in bulk, and
 * a second run is NO CHANGE.
 */
#[Signature('leave:reconcile-hr-history
    {--register= : Register file (defaults to database/data/conexus_csl_register_2026_27.php)}
    {--cutoff=2026-09-30 : Last day the HR register covers}
    {--employee=* : Limit to these employee codes (e.g. CNS021)}
    {--apply : Restate ONE employee (needs exactly one --employee and --actor) to the HR breakdown; the total never changes}
    {--actor= : Email of the HR user making the change (required with --apply)}
    {--reason=Restated to the authoritative HR register snapshot : Recorded on every entry and in the audit log}
    {--details : List every ledger entry read for each employee, and whether it counts}
    {--csv= : Also write the rows to this CSV path}')]
#[Description('HR register snapshot + portal activity after the cutoff vs current Earned Leave (preview writes nothing; --apply restates one employee)')]
class ReconcileHrLeaveHistory extends Command
{
    public function handle(LeaveRegisterReconciliationService $registers, History $reconciler): int
    {
        $path = $this->option('register') ?: database_path('data/conexus_csl_register_2026_27.php');
        $codes = array_values(array_filter((array) $this->option('employee')));
        $apply = (bool) $this->option('apply');

        $actor = null;
        if ($apply) {
            if (count($codes) !== 1) {
                $this->error('--apply restates one employee at a time: pass exactly one --employee=<code>. Bulk apply is not available.');

                return self::FAILURE;
            }
            $actor = User::where('email', (string) $this->option('actor'))->first();
            if (! $actor || ! $actor->hasPermission('correct_leave_balance')) {
                $this->error('--apply needs --actor=<email> of an HR user with the correct_leave_balance permission.');

                return self::FAILURE;
            }
        }

        DB::beginTransaction();

        try {
            $register = $registers->loadRegister($path);
            $year = $registers->yearFor($register['leave_year']);
            $cutoff = Carbon::parse((string) $this->option('cutoff'))->startOfDay();
            if (! $cutoff->between($year->starts_on, $year->ends_on)) {
                throw new DomainException("The cutoff {$cutoff->toDateString()} is outside {$year->label}.");
            }

            $rows = $reconciler->plan($register, $year, $cutoff, $codes);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            DB::rollBack();
        }

        $this->info("HR leave history reconciliation · {$year->label} · HR snapshot to {$cutoff->format('d M Y')} · "
            .($apply ? 'APPLY by '.$actor->email : 'PREVIEW — nothing is written'));
        $this->line('Source: '.$register['source']);

        if ($rows->isEmpty()) {
            $this->warn($codes === [] ? 'Nothing to reconcile.' : 'No register row or Earned Leave employee for '.implode(', ', $codes).'.');

            return $apply ? self::FAILURE : self::SUCCESS;
        }

        $this->report($rows, $cutoff);
        if ($this->option('details')) {
            $this->details($rows);
        }

        if ($csv = $this->option('csv')) {
            $this->writeCsv($rows, (string) $csv, $cutoff);
        }

        return $apply ? $this->applyOne($rows->sole(), $reconciler, $actor) : self::SUCCESS;
    }

    private function applyOne(array $row, History $reconciler, User $actor): int
    {
        if ($row['status'] === History::NO_CHANGE) {
            $this->info("NO CHANGE — {$row['code']} is already restated to the HR register.");

            return self::SUCCESS;
        }
        if ($row['status'] !== History::MATCH || abs((float) $row['difference']) >= 0.005 || $row['apply_blocker'] !== null) {
            $this->error("Refused: {$row['code']} is {$row['status']}".($row['apply_blocker'] ? ' — '.$row['apply_blocker'] : ' — '.$row['reason']));

            return self::FAILURE;
        }

        try {
            $result = $reconciler->apply($row, $actor, (string) $this->option('reason'));
        } catch (DomainException|RuntimeException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['status'] !== History::RECONCILED) {
            $this->error("Refused on re-check: {$result['status']} — {$result['reason']}");

            return self::FAILURE;
        }

        $this->breakdownTable($result['before_lines'], $result['after_lines']);
        $this->info("RECONCILED — {$result['reason']}");

        return self::SUCCESS;
    }

    /** Every ledger entry the reconciliation read, and how. */
    private function details(Collection $rows): void
    {
        foreach ($rows->filter(fn (array $r) => ($r['entries'] ?? []) !== []) as $r) {
            $this->line("Entries read for {$r['employee']} ({$r['code']}):");
            $this->table(['#', 'Ledger', 'Type', 'Days', 'Dated', 'Source', 'Reverses', 'Read as', 'Counted'],
                collect($r['entries'])->map(fn (array $e) => [$e['id'], $e['ledger'], str_replace('_', ' ', $e['type']), $this->n($e['days']), $e['date'],
                    $e['source'] ?? '—', $e['reverses'] ? '#'.$e['reverses'] : '—', $e['class'], $e['counted'] ? 'yes' : 'no'])->all());
        }
    }

    /** Before and after source breakdown, side by side. */
    private function breakdownTable(array $before, array $after): void
    {
        $labels = ['carry_forward' => 'Carry Forward', 'current_year_accrued' => 'Current Year Accrued', 'opening' => 'Migrated Opening',
            'hr_adjustment' => 'HR Adjustments', 'historical_used' => 'Used to cutoff (HR register)', 'portal_used' => 'Used in the portal',
            'used' => 'Used (total)', 'expired' => 'Expired', 'total_available' => 'Total Available', 'pending' => 'Pending'];
        $this->table(['Earned Leave', 'Before', 'After'],
            collect($labels)->map(fn (string $label, string $key) => [$label, $this->n($before[$key] ?? 0), $this->n($after[$key] ?? 0)])->values()->all());
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function report(Collection $rows, Carbon $cutoff): void
    {
        $after = 'After '.$cutoff->format('d M');
        $this->table(
            ['Employee', 'Code', 'HR Carry Forward', 'HR Current Year Earned', 'HR Historical Used', 'HR Baseline Available at '.$cutoff->format('d M Y'),
                "Portal Used {$after}", "Portal Adjustments {$after}", "Portal Accrual {$after}", 'Portal Pending',
                'Expected Current Available', 'Existing Current Available', 'Difference', 'Status', 'Reason'],
            $rows->map(fn (array $r) => [
                $r['employee'], $r['code'] ?? '—', $this->n($r['hr_carry_forward']), $this->n($r['hr_current_year']), $this->n($r['hr_used']),
                $this->n($r['hr_baseline']), $this->n($r['used_after']), $this->n($r['adjustments_after']), $this->n($r['accrual_after']),
                $this->n($r['pending']), $this->n($r['expected']), $this->n($r['existing']), $this->n($r['difference']), $r['status'], $r['reason'],
            ])->all(),
        );

        if ($rows->count() === 1 && ($lines = $rows->first()['existing_lines'])) {
            $this->line('Current source breakdown: '.collect(['carry_forward' => 'carry forward', 'current_year_accrued' => 'current year', 'opening' => 'migrated opening',
                'hr_adjustment' => 'HR adjustments', 'used' => 'used', 'total_available' => 'available'])
                ->map(fn (string $label, string $key) => $label.' '.$this->n($lines[$key] ?? 0))->implode(' · '));
        }

        $this->line($rows->countBy('status')->map(fn (int $n, string $status) => "{$status}: {$n}")->implode(' · '));
        $this->line('Pending requests are shown only; they never reduce the expected or existing figure.');
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function writeCsv(Collection $rows, string $path, Carbon $cutoff): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['employee', 'code', 'hr_carry_forward', 'hr_current_year_earned', 'hr_historical_used', 'hr_baseline_'.$cutoff->toDateString(),
            'portal_used_after', 'portal_adjustments_after', 'portal_accrual_after', 'portal_pending',
            'expected_current_available', 'existing_current_available', 'difference', 'status', 'reason']);
        foreach ($rows as $r) {
            fputcsv($handle, [$r['employee'], $r['code'], $r['hr_carry_forward'], $r['hr_current_year'], $r['hr_used'], $r['hr_baseline'],
                $r['used_after'], $r['adjustments_after'], $r['accrual_after'], $r['pending'],
                $r['expected'], $r['existing'], $r['difference'], $r['status'], $r['reason']]);
        }
        fclose($handle);
        $this->line("CSV written to {$path}");
    }

    private function n(mixed $v): string
    {
        return $v === null ? '—' : (rtrim(rtrim(number_format((float) $v, 2), '0'), '.') ?: '0');
    }
}
