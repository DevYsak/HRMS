<?php

namespace App\Console\Commands;

use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\EarnedLeaveRolloverService;
use App\Services\Leave\LeaveRolloverService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The leave-year rollover. Preview by default; --apply processes the SAFE
 * rows only (carry forward, expire the rest, provision the new base) and
 * records the ambiguous ones for HR review. Scheduled for 1 July and safe
 * to re-run: processed rows are never processed again.
 *
 * Leave V2 Earned Leave rolls over beside it (EarnedLeaveRolloverService):
 * the final June accrual must be in, then the policy's carry forward is
 * credited into the new year under one deterministic key.
 */
#[Signature('leave:rollover
    {--from= : Closing leave year label, e.g. 2025/26 (defaults to the year that just ended)}
    {--preview : Show what would happen and write nothing (default)}
    {--apply : Process the SAFE / READY rows}
    {--employee=* : Earned Leave only: limit to these employee codes}
    {--actor= : Email of the HR / admin user running it by hand (scheduled runs are the system)}
    {--earned-only : Only the Leave V2 Earned Leave rollover}')]
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
        $actor = $this->option('actor') ? User::where('email', (string) $this->option('actor'))->first() : null;
        if ($this->option('actor') && ! $actor) {
            $this->error('No user with that --actor email.');

            return self::FAILURE;
        }

        $earnedFailed = $this->earnedLeave(app(EarnedLeaveRolloverService::class), $from, $to, (bool) $this->option('apply'), $actor);
        if ($this->option('earned-only')) {
            return $earnedFailed ? self::FAILURE : self::SUCCESS;
        }

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

        return $result['failed'] > 0 || $earnedFailed ? self::FAILURE : self::SUCCESS;
    }

    /** The Leave V2 Earned Leave year-end; true when a row failed. */
    private function earnedLeave(EarnedLeaveRolloverService $service, LeaveYear $from, LeaveYear $to, bool $apply, ?User $actor): bool
    {
        $failed = false;
        if (! $apply) {
            DB::beginTransaction();
            try {
                $this->earnedReport($service->plan($from, $to, $this->option('employee')), "{$from->label} → {$to->label} · PREVIEW — nothing is written");
            } finally {
                DB::rollBack();
            }

            return false;
        }

        $trigger = $actor ? 'manual' : 'scheduled';
        $rows = $service->plan($from, $to, $this->option('employee'))->map(function (array $row) use ($service, $actor, $trigger, &$failed) {
            if ($row['status'] !== EarnedLeaveRolloverService::READY) {
                return $row;
            }
            try {
                return $service->apply($row, $actor, $trigger);
            } catch (Throwable $e) {
                $failed = true;

                return ['status' => 'FAILED', 'reason' => 'Rolled back: '.$e->getMessage()] + $row;
            }
        });
        $this->earnedReport($rows, "{$from->label} → {$to->label} · APPLIED".($actor ? ' by '.$actor->email : ' by the system'));

        return $failed;
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function earnedReport(Collection $rows, string $heading): void
    {
        $this->line("<comment>Earned Leave rollover · {$heading}</comment>");
        $this->table(['Employee', 'Code', 'Policy', 'Closing', 'Carry', 'Lapses', 'Max', 'Expires', 'Status', 'Reason'],
            $rows->map(fn (array $r) => [$r['employee'], $r['code'], $r['policy'] ?? '—', $r['closing'] ?? '—', $r['carry'], $r['lapse'], $r['cap'] ?? '—', $r['expires_on'] ?? '—', $r['status'], $r['reason']])->all());
        $this->line('  '.$rows->count().' employee(s): '.($rows->countBy('status')->map(fn ($n, $st) => "{$st} {$n}")->implode(' · ') ?: 'none'));
    }
}
