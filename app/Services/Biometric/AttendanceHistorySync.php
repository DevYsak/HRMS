<?php

namespace App\Services\Biometric;

use App\Jobs\RunAttendanceHistorySync;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Syncs past attendance from the engine's logs through the SAME day sync the
 * live schedule uses (EngineAttendanceSyncService::syncDate), one day at a
 * time — nothing about the live sync or its API changes. Payroll-locked days
 * and days HR corrected are protected by syncDate itself, and re-running a
 * day gives the same result.
 *
 * A run is a background job chain (31 days per job) whose progress lives in
 * the cache, so the Biometric Control panel can follow it. "Check logs" is
 * the same walk without writing: it shows which days have punches and which
 * device PINs have no HRMS employee (their punches cannot be synced until the
 * PIN is set as that employee's Employee Code).
 */
class AttendanceHistorySync
{
    public const CACHE_KEY = 'attendance:history-sync';

    public const MODE_CHECK = 'check';

    public const MODE_SYNC = 'sync';

    /** Days one job handles before handing over to the next. */
    public const CHUNK_DAYS = 31;

    /** Longest range one run covers. */
    public const MAX_DAYS = 1100;

    /** A run with no progress for this long is treated as stopped (worker died). */
    public const STALE_MINUTES = 15;

    public function __construct(
        private EngineHistoryScanner $scanner,
        private EngineAttendanceSyncService $sync,
    ) {}

    /** @return array<string, mixed>|null the current or last run */
    public function status(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    public function isRunning(?array $status = null): bool
    {
        $status ??= $this->status();

        return $status !== null
            && in_array($status['status'], ['queued', 'running', 'cancelling'], true)
            && ! $this->isStale($status);
    }

    public function isStale(array $status): bool
    {
        return in_array($status['status'], ['queued', 'running', 'cancelling'], true)
            && Carbon::parse($status['updated_at'])->lt(now()->subMinutes(self::STALE_MINUTES));
    }

    /**
     * Start a run in the background. Returns an error message instead when
     * it cannot start.
     */
    public function start(string $from, string $to, string $mode, User $by): ?string
    {
        if (config('queue.default') === 'sync') {
            return 'The background worker is not set up (QUEUE_CONNECTION=sync), so a long run would stop this page. '
                ."Run it on the server instead: php artisan attendance:sync-engine --from={$from} --to={$to}";
        }

        if ($error = $this->rangeError($from, $to)) {
            return $error;
        }

        if ($this->isRunning()) {
            return 'A history run is already in progress. Wait for it to finish or stop it first.';
        }

        $status = $this->fresh($from, $to, $mode) + [
            'started_by' => $by->name,
            'started_by_id' => $by->id,
        ];
        $this->save($status);

        AuditLog::record($by, 'attendance.history_sync_started', null, ['mode' => $mode, 'from' => $from, 'to' => $to]);

        RunAttendanceHistorySync::dispatch($status['run_id']);

        return null;
    }

    /** Ask the running job to stop after the day it is on. */
    public function cancel(User $by): void
    {
        $status = $this->status();

        if ($status === null || ! in_array($status['status'], ['queued', 'running', 'cancelling'], true)) {
            return;
        }

        // A run whose worker died stops at once; a live one after its current day.
        $status['status'] = $this->isStale($status) ? 'cancelled' : 'cancelling';
        $status['cancelled_by'] = $by->name;
        $this->save($status);
    }

    /**
     * The next batch of days for this run. Returns true while days remain.
     */
    public function processChunk(string $runId): bool
    {
        $status = $this->status();

        if ($status === null || $status['run_id'] !== $runId || ! in_array($status['status'], ['queued', 'running', 'cancelling'], true)) {
            return false;
        }

        $status['status'] = $status['status'] === 'cancelling' ? 'cancelling' : 'running';
        $status['started_at'] ??= now()->toIso8601String();
        $codes = Employee::whereNotNull('employee_code')->pluck('employee_code')->map(fn ($c) => (int) $c)->flip()->all();

        for ($i = 0; $i < self::CHUNK_DAYS && $status['cursor'] <= $status['to']; $i++) {
            // A stop asked for from the page wins before the next day starts.
            if ($status['status'] === 'cancelling') {
                $status['status'] = 'cancelled';
                $status['finished_at'] = now()->toIso8601String();
                $this->save($status);

                return false;
            }

            $this->runDay($status, $status['cursor'], $codes);
            $status['cursor'] = Carbon::parse($status['cursor'])->addDay()->toDateString();

            // Pick up a stop pressed while the day ran; a newer run replaces this one.
            $latest = $this->status();
            if (($latest['run_id'] ?? null) !== $runId) {
                return false;
            }
            if (in_array($latest['status'] ?? null, ['cancelling', 'cancelled'], true)) {
                $status['status'] = 'cancelling';
                $status['cancelled_by'] = $latest['cancelled_by'] ?? null;
            }

            $this->save($status);
        }

        if ($status['status'] === 'cancelling') {
            $status['status'] = 'cancelled';
            $status['finished_at'] = now()->toIso8601String();
            $this->save($status);

            return false;
        }

        if ($status['cursor'] > $status['to']) {
            $status['status'] = 'finished';
            $status['finished_at'] = now()->toIso8601String();
            $this->save($status);

            if ($status['mode'] === self::MODE_SYNC && ($by = User::find($status['started_by_id'] ?? null))) {
                AuditLog::record($by, 'attendance.history_sync_finished', null, array_intersect_key($status, array_flip(
                    ['from', 'to', 'days_done', 'days_with_punches', 'synced', 'skipped'])) + ['failed_days' => count($status['failed'])]);
            }

            return false;
        }

        return true;
    }

    /**
     * Walk a range now, without the queue — for the command line. Same
     * result as a background run.
     *
     * @param  callable(string): void|null  $onDay
     * @return array<string, mixed>
     */
    public function runNow(string $from, string $to, string $mode, ?callable $onDay = null): array
    {
        $status = $this->fresh($from, $to, $mode);
        $codes = Employee::whereNotNull('employee_code')->pluck('employee_code')->map(fn ($c) => (int) $c)->flip()->all();

        while ($status['cursor'] <= $status['to']) {
            $this->runDay($status, $status['cursor'], $codes);
            $onDay && $onDay($status['cursor']);
            $status['cursor'] = Carbon::parse($status['cursor'])->addDay()->toDateString();
        }

        $status['status'] = 'finished';

        return $status;
    }

    public function rangeError(string $from, string $to): ?string
    {
        try {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->startOfDay();
        } catch (\Throwable) {
            return 'Enter valid From and To dates.';
        }

        return match (true) {
            $start->gt($end) => 'From must be on or before To.',
            $end->gt(Carbon::today()) => 'To cannot be in the future.',
            (int) $start->diffInDays($end) + 1 > self::MAX_DAYS => 'Choose up to '.self::MAX_DAYS.' days at a time.',
            default => null,
        };
    }

    /**
     * One day: read the log, then (sync mode) sync it the way the live
     * schedule does.
     *
     * @param  array<string, mixed>  $status
     * @param  array<int, int>  $codes  HRMS employee codes (flipped)
     */
    private function runDay(array &$status, string $date, array $codes): void
    {
        $status['current'] = $date;
        $status['days_done']++;

        try {
            $scan = $this->scanner->day($date);

            if ($scan['error'] !== null) {
                $status['failed'][$date] = $scan['error'];

                return;
            }

            if ($scan['pins'] === []) {
                return;
            }

            $status['days_with_punches']++;
            $status['first_punch_date'] ??= $date;
            $status['last_punch_date'] = $date;

            foreach ($scan['pins'] as $pin => $seen) {
                if (isset($codes[$pin])) {
                    $status['matched'][$pin] = true;

                    continue;
                }

                $known = $status['unmatched'][$pin] ?? ['name' => null, 'days' => 0, 'first' => $date, 'last' => $date];
                $status['unmatched'][$pin] = [
                    'name' => $seen['name'] ?? $known['name'],
                    'days' => $known['days'] + 1,
                    'first' => $known['first'],
                    'last' => $date,
                ];
            }

            if ($status['mode'] !== self::MODE_SYNC) {
                return;
            }

            $result = $this->sync->syncDate($date);

            if ($result['error'] !== null) {
                $status['failed'][$date] = $result['error'];

                return;
            }

            $status['synced'] += $result['synced'];
            $status['skipped'] += $result['skipped'];
        } catch (\Throwable $e) {
            report($e);
            $status['failed'][$date] = Str::limit($e->getMessage(), 160);
        }
    }

    /** @return array<string, mixed> */
    private function fresh(string $from, string $to, string $mode): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        return [
            'run_id' => (string) Str::uuid(),
            'mode' => $mode === self::MODE_SYNC ? self::MODE_SYNC : self::MODE_CHECK,
            'status' => 'queued',
            'from' => $from,
            'to' => $to,
            'cursor' => $from,
            'current' => null,
            'days_total' => (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1,
            'days_done' => 0,
            'days_with_punches' => 0,
            'first_punch_date' => null,
            'last_punch_date' => null,
            'matched' => [],
            'unmatched' => [],
            'synced' => 0,
            'skipped' => 0,
            'failed' => [],
            'queued_at' => now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $status */
    private function save(array $status): void
    {
        $status['updated_at'] = now()->toIso8601String();
        Cache::forever(self::CACHE_KEY, $status);
    }
}
