<?php

namespace App\Console\Commands;

use App\Services\Biometric\AttendanceHistorySync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * What the attendance engine's logs hold for a period — days with punches,
 * the first and last one, and device PINs with no HRMS employee — without
 * writing anything. Run it before a history sync
 * (attendance:sync-engine --from= --to=) so every PIN can be matched first.
 */
#[Signature('attendance:history-coverage
    {--from= : First day to check (Y-m-d)}
    {--to= : Last day to check (Y-m-d); defaults to today}')]
#[Description('Read-only: which days the engine logs have punches for, and which device PINs have no HRMS employee')]
class AttendanceHistoryCoverage extends Command
{
    public function handle(AttendanceHistorySync $history): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: now()->toDateString());

        if ($from === '') {
            $this->error('Give --from=YYYY-MM-DD (the first day to check).');

            return self::FAILURE;
        }

        if ($error = $history->rangeError($from, $to)) {
            $this->error($error);

            return self::FAILURE;
        }

        $this->info("Engine log check · {$from} → {$to} · READ ONLY — nothing is written");
        $result = $history->runNow($from, $to, AttendanceHistorySync::MODE_CHECK);

        $this->table(['Days checked', 'Days with punches', 'First punch', 'Last punch', 'Employees matched', 'PINs with no employee', 'Days the engine could not answer'], [[
            $result['days_done'], $result['days_with_punches'], $result['first_punch_date'] ?? '—', $result['last_punch_date'] ?? '—',
            count($result['matched']), count($result['unmatched']), count($result['failed']),
        ]]);

        if ($result['unmatched'] !== []) {
            $this->warn('These device PINs have punches but no HRMS employee with that Employee Code — their attendance cannot sync until the code is set on the right employee:');
            $this->table(['PIN', 'Name in the engine', 'Days with punches', 'First seen', 'Last seen'], collect($result['unmatched'])
                ->sortKeys()
                ->map(fn (array $u, int $pin) => [$pin, $u['name'] ?? '—', $u['days'], $u['first'], $u['last']])
                ->values()->all());
        }

        foreach ($result['failed'] as $date => $error) {
            $this->line("  {$date}: {$error}");
        }

        if ($result['days_with_punches'] > 0) {
            $this->newLine();
            $this->line('To sync this history (same process as the live sync; locked and HR-corrected days are never changed):');
            $this->line("  php artisan attendance:sync-engine --from={$result['first_punch_date']} --to={$to}");
        }

        return self::SUCCESS;
    }
}
