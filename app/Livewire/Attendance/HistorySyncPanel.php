<?php

namespace App\Livewire\Attendance;

use App\Services\Biometric\AttendanceHistorySync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Biometric Control → Attendance history sync. Starts a background run that
 * replays the engine's logs through the live day sync ("Sync history"), or
 * only reads them ("Check logs"), and follows its progress.
 */
class HistorySyncPanel extends Component
{
    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->authorizeFleet();
        $this->from = Carbon::today()->subMonths(6)->startOfMonth()->toDateString();
        $this->to = Carbon::today()->toDateString();
    }

    public function check(AttendanceHistorySync $history): void
    {
        $this->begin($history, AttendanceHistorySync::MODE_CHECK);
    }

    public function sync(AttendanceHistorySync $history): void
    {
        $this->begin($history, AttendanceHistorySync::MODE_SYNC);
    }

    public function cancel(AttendanceHistorySync $history): void
    {
        $this->authorizeFleet();
        $history->cancel(Auth::user());
    }

    public function render(AttendanceHistorySync $history)
    {
        $this->authorizeFleet();
        $status = $history->status();

        return view('livewire.attendance.history-sync-panel', [
            'status' => $status,
            'running' => $history->isRunning($status),
            'stale' => $status !== null && $history->isStale($status),
            'waitingForWorker' => $status !== null && $status['status'] === 'queued'
                && Carbon::parse($status['queued_at'])->lt(now()->subMinute()),
            'queueIsSync' => config('queue.default') === 'sync',
        ]);
    }

    private function begin(AttendanceHistorySync $history, string $mode): void
    {
        $this->authorizeFleet();
        $this->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:today'],
        ]);

        if ($error = $history->start($this->from, $this->to, $mode, Auth::user())) {
            $this->addError('from', $error);

            return;
        }

        \Flux::toast(
            text: $mode === AttendanceHistorySync::MODE_SYNC
                ? 'History sync started in the background. You can leave this page; progress shows here.'
                : 'Checking the logs in the background — nothing is written.',
            variant: 'success',
        );
    }

    /** Re-syncing history rewrites attendance company-wide: biometric managers only. */
    private function authorizeFleet(): void
    {
        abort_unless(Auth::user()?->hasPermission('manage_biometric'), 403);
    }
}
