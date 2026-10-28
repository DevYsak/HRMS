<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Leave\EarnedOpeningSourceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What each Leave V2 migration opening is made of, read from the source CSL
 * ledger: carry forward from the previous year, current-year CSL credits, or
 * something that cannot be classified (AMBIGUOUS). See
 * EarnedOpeningSourceService.
 *
 * The preview (default) writes nothing. --apply reclassifies exactly ONE
 * employee (--employee) on an HR instruction (--actor, --reason): never in
 * bulk, never an AMBIGUOUS row, and the available balance never changes.
 */
#[Signature('leave:earned-opening-sources
    {--preview : Show what each opening is made of and write nothing (default)}
    {--apply : Reclassify ONE employee\'s opening as the evidence shows (needs --employee, --actor and --reason)}
    {--employee=* : Limit to these employee codes}
    {--actor= : Email of the HR user making the change (required with --apply)}
    {--reason= : Why HR is reclassifying it (required with --apply)}
    {--csv= : Also write the rows to this CSV path}')]
#[Description('Evidence for each Leave V2 migrated Earned Leave opening: carry forward vs current-year CSL credit (preview writes nothing)')]
class EarnedOpeningSources extends Command
{
    public function handle(EarnedOpeningSourceService $sources): int
    {
        $codes = array_values(array_filter((array) $this->option('employee')));
        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('preview')) {
            $this->error('Choose --preview or --apply, not both.');

            return self::FAILURE;
        }

        $actor = null;
        if ($apply) {
            if (count($codes) !== 1) {
                $this->error('--apply reclassifies one employee at a time: pass exactly one --employee=<code>.');

                return self::FAILURE;
            }
            $actor = User::where('email', (string) $this->option('actor'))->first();
            if (! $actor || ! $actor->hasPermission('correct_leave_balance')) {
                $this->error('--apply needs --actor=<email> of an HR user with the correct_leave_balance permission.');

                return self::FAILURE;
            }
            if (trim((string) $this->option('reason')) === '') {
                $this->error('--apply needs --reason="…" recording why HR is reclassifying this opening.');

                return self::FAILURE;
            }
        }

        $rows = $sources->plan($codes);
        if ($rows->isEmpty()) {
            $this->warn($codes === [] ? 'No Leave V2 migration records.' : 'No Leave V2 migration record for '.implode(', ', $codes).'.');

            return self::SUCCESS;
        }

        if ($apply) {
            $rows = $rows->map(function (array $row) use ($sources, $actor) {
                if (! in_array($row['status'], EarnedOpeningSourceService::CLASSIFIABLE, true)) {
                    return $row;
                }
                try {
                    return $sources->apply($row, $actor, (string) $this->option('reason'));
                } catch (Throwable $e) {
                    return ['status' => 'FAILED', 'reason' => $e->getMessage()] + $row;
                }
            });
        }

        $this->report($rows, $apply ? 'APPLIED by '.$actor->email : 'PREVIEW — nothing written');

        if ($path = $this->option('csv')) {
            $this->writeCsv($rows, (string) $path);
        }

        return $rows->contains('status', 'FAILED') ? self::FAILURE : self::SUCCESS;
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function report(Collection $rows, string $mode): void
    {
        $this->info("Earned Leave opening sources — {$mode}");
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') ?: '0';

        $this->table(
            ['Code', 'Employee', 'Year', 'Opening', 'Carry Fwd', 'Current Year', 'Other', 'Status', 'Reason'],
            $rows->map(fn (array $r) => [$r['code'], $r['employee'], $r['year'], $fmt($r['migrated']), $fmt($r['carry_forward']),
                $fmt($r['current_year']), $fmt($r['other']), $r['status'], $r['reason']])->all(),
        );

        $this->line($rows->countBy('status')->map(fn (int $n, string $status) => "{$status}: {$n}")->implode(' · '));
        $this->line('Classification is evidence only: nothing changes without --apply for one named employee.');
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function writeCsv(Collection $rows, string $path): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['employee_code', 'employee', 'leave_year', 'opening', 'carry_forward', 'current_year', 'other', 'status', 'reason', 'source_lots']);
        foreach ($rows as $r) {
            fputcsv($handle, [$r['code'], $r['employee'], $r['year'], $r['migrated'], $r['carry_forward'], $r['current_year'], $r['other'],
                $r['status'], $r['reason'], implode(' ', array_map(fn ($lot) => '#'.$lot['id'].':'.$lot['type'].':'.$lot['remaining'], $r['lots']))]);
        }
        fclose($handle);
        $this->line("CSV written to {$path}");
    }
}
