<?php

namespace App\Jobs;

use App\Services\Biometric\AttendanceHistorySync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One batch of days of a history run (AttendanceHistorySync::CHUNK_DAYS);
 * queues the next batch until the range is done. Small batches keep every
 * job well inside the worker's time limit, and a stop takes effect between
 * days.
 */
class RunAttendanceHistorySync implements ShouldQueue
{
    use Queueable;

    /** A batch is never retried: a re-run of the range is safe and explicit. */
    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public string $runId) {}

    public function handle(AttendanceHistorySync $history): void
    {
        if ($history->processChunk($this->runId)) {
            self::dispatch($this->runId);
        }
    }
}
