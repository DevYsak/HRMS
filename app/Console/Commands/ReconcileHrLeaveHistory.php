<?php

namespace App\Console\Commands;

use App\Services\Leave\LeaveHrHistoryReconciliationService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * READ ONLY: compares the HR register (the authoritative snapshot up to the
 * cutoff) plus everything the portal did after it with today's Earned Leave
 * balance. See LeaveHrHistoryReconciliationService for the formula.
 *
 * There is no --apply: the whole run happens inside a database transaction
 * that is always rolled back, so not even a helper can leave a row behind.
 */
#[Signature('leave:reconcile-hr-history
    {--register= : Register file (defaults to database/data/conexus_csl_register_2026_27.php)}
    {--cutoff=2026-09-30 : Last day the HR register covers}
    {--employee=* : Limit to these employee codes (e.g. CNS021)}
    {--csv= : Also write the rows to this CSV path}')]
#[Description('Preview only: HR register snapshot + portal activity after the cutoff vs current Earned Leave (writes nothing)')]
class ReconcileHrLeaveHistory extends Command
{
    public function handle(LeaveRegisterReconciliationService $registers, LeaveHrHistoryReconciliationService $reconciler): int
    {
        $path = $this->option('register') ?: database_path('data/conexus_csl_register_2026_27.php');
        $codes = array_values(array_filter((array) $this->option('employee')));

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

        $this->info("HR leave history reconciliation · {$year->label} · HR snapshot to {$cutoff->format('d M Y')} · PREVIEW — nothing is written");
        $this->line('Source: '.$register['source']);

        if ($rows->isEmpty()) {
            $this->warn($codes === [] ? 'Nothing to reconcile.' : 'No register row or Earned Leave employee for '.implode(', ', $codes).'.');

            return self::SUCCESS;
        }

        $this->report($rows, $cutoff);

        if ($csv = $this->option('csv')) {
            $this->writeCsv($rows, (string) $csv, $cutoff);
        }

        return self::SUCCESS;
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
