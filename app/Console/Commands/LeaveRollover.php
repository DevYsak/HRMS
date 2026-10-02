<?php

namespace App\Console\Commands;

use App\Models\LeaveYear;
use App\Services\Leave\LeaveRolloverService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The leave-year rollover. Preview by default; --apply processes the SAFE
 * rows only (carry forward, expire the rest, provision the new base) and
 * records the ambiguous ones for HR review. Scheduled for 1 July and safe
 * to re-run: processed rows are never processed again.
 */
#[Signature('leave:rollover {--from= : Closing leave year label, e.g. 2025/26 (defaults to the year that just ended)} {--apply : Process the SAFE rows (otherwise preview only)}')]
#[Description('Preview or run the year-end leave rollover (carry forward, expiry, new-year entitlement)')]
class LeaveRollover extends Command
{
    public function handle(LeaveRolloverService $rollover, LeaveYearResolver $years): int
    {
        $from = $this->option('from')
            ? LeaveYear::where('label', $this->option('from'))->first()
            : $years->previous($years->current());

        if ($from === null) {
            $this->error('No leave year labelled '.$this->option('from').'.');

            return self::FAILURE;
        }

        $to = $years->next($from);

        if (! $this->option('apply')) {
            $rows = $rollover->preview($from, $to);
            $this->table(['Employee', 'Type', 'Closing', 'Carry', 'Expire', 'New base', 'New opening', 'Status', 'Reason'],
                $rows->map(fn (array $r) => [$r['employee'], $r['leave_type'], $r['closing'], $r['carry'], $r['expire'], $r['new_base'], $r['new_opening'], $r['status'], $r['reason']])->all());
            $counts = $rows->countBy('status');
            $this->info(sprintf('Preview %s → %s: %d SAFE, %d NEEDS_HR_REVIEW, %d BLOCKED, %d already processed. Nothing was changed.',
                $from->label, $to->label, $counts['SAFE'] ?? 0, $counts['NEEDS_HR_REVIEW'] ?? 0, $counts['BLOCKED'] ?? 0, $counts['PROCESSED'] ?? 0));

            return self::SUCCESS;
        }

        $result = $rollover->process($from, $to, null, trigger: 'scheduled');

        $this->info(sprintf('Rollover %s → %s: %d processed, %d already, %d need HR review, %d failed; %d new-year entitlement(s) provisioned.',
            $from->label, $to->label, $result['processed'], $result['already'], $result['needs_review'], $result['failed'], $result['provisioned']));

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
