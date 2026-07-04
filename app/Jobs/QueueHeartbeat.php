<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * A trivial queued job the scheduler dispatches every minute. Because only a
 * running queue worker can process it, the timestamp it stamps is a reliable
 * liveness signal: if `queue:heartbeat_at` is fresh, a worker is alive and
 * draining the queue; if it's stale, the worker is down or stuck.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('queue:heartbeat_at', now()->timestamp, now()->addMinutes(30));
    }
}
