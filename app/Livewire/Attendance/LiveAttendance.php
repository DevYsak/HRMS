<?php

namespace App\Livewire\Attendance;

use App\Services\Attendance\LiveAttendanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Live Attendance — today's punches and each person's status for everyone
 * the viewer's view_live_attendance scope reaches. Refreshes itself every
 * 30 seconds (wire:poll on the panel only, never a page reload) and on
 * "Refresh now". Figures come from LiveAttendanceService, which reads the
 * canonical attendance services; nothing is calculated here.
 */
class LiveAttendance extends Component
{
    /** Panel tab: overview | activity | status */
    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $department = '';

    #[Url]
    public string $shift = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    /** Latest activity shown (20, then "View more activity"). */
    public int $activityLimit = 20;

    /** Today Status rows shown. */
    public int $statusLimit = 50;

    public const MAX_ACTIVITY = 100;

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function refreshNow(): void
    {
        // A render is the refresh; the snapshot is rebuilt in render().
    }

    public function viewMoreActivity(): void
    {
        $this->activityLimit = min(self::MAX_ACTIVITY, $this->activityLimit + 30);
        $this->tab = 'activity';
    }

    public function showMoreStatus(): void
    {
        $this->statusLimit += 50;
    }

    /** "Needs attention" chips apply the matching status filter. */
    public function applyAttention(string $status): void
    {
        $this->status = array_key_exists($status, LiveAttendanceService::STATUSES) ? $status : '';
        $this->tab = 'status';
    }

    public function updatedStatus(): void
    {
        if (! array_key_exists($this->status, LiveAttendanceService::STATUSES)) {
            $this->status = '';
        }
    }

    public function render(LiveAttendanceService $live): View
    {
        // Every request (including each poll) — access can be withdrawn mid-session.
        $this->authorizeView();

        $snapshot = $live->snapshot(Auth::user(), [
            'department' => $this->department,
            'shift' => $this->shift,
            'status' => $this->status,
            'search' => $this->search,
        ], $this->activityLimit);

        return view('livewire.attendance.live-attendance', $snapshot + [
            'syncedAt' => now(),
            'statusOptions' => LiveAttendanceService::STATUSES,
        ])->layout('layouts.app', ['title' => 'Live Attendance']);
    }

    private function authorizeView(): void
    {
        abort_unless(Auth::user()?->hasPermission(LiveAttendanceService::PERMISSION), 403);
    }
}
