<?php

namespace App\Livewire\Attendance;

use App\Notifications\MissedPunchAllowanceExceededNotification;
use App\Notifications\MissedPunchNotification;
use App\Notifications\MissedPunchWeeklyWarningNotification;
use App\Services\Attendance\MissedPunchTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * The missed-punch popup, on every page for the signed-in employee: each
 * missed punch of the last 7 days that is not regularised and not yet seen,
 * and the working week (Mon–Fri) reaching the early warning or going over
 * the allowance (MissedPunchTracker), each once.
 * Read from live attendance data (refreshed every 2 minutes); "Got it"
 * remembers what was seen and marks the matching notifications read.
 */
class MissedPunchPopup extends Component
{
    /** How far back an unseen missed punch still pops up. */
    public const LOOKBACK_DAYS = 6;

    public function dismiss(): void
    {
        $user = Auth::user();
        $state = $this->state();

        if ($user === null || $state === null) {
            return;
        }

        $seen = $this->seen();
        foreach ($state['missed'] as $missed) {
            $seen[] = $missed['key'];
        }
        if ($state['warn']) {
            $seen[] = $state['week_key'];
        }
        Cache::forever($this->seenKey(), array_slice(array_values(array_unique($seen)), -300));

        $user->unreadNotifications()
            ->whereIn('type', [MissedPunchNotification::class, MissedPunchWeeklyWarningNotification::class, MissedPunchAllowanceExceededNotification::class])
            ->update(['read_at' => now()]);
    }

    /** Mark seen, then open the regularisation request for that day. */
    public function regularise(string $date): void
    {
        $this->dismiss();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->redirectRoute('attendance.my', ['regularise' => $date]);
        }
    }

    public function render()
    {
        return view('livewire.attendance.missed-punch-popup', ['state' => $this->state()]);
    }

    /**
     * What to show now, or null when there is nothing new.
     *
     * @return array{missed: list<array<string, mixed>>, week_count: int, warn: bool, week_key: string, status: string, allowance: int, week_start: string}|null
     */
    private function state(): ?array
    {
        $employee = Auth::user()?->employee;

        if ($employee === null) {
            return null;
        }

        $today = Carbon::today();
        $weekStart = MissedPunchTracker::weekStart($today)->toDateString();

        // The same 2-minute snapshot for every page in between, so the popup costs
        // one cache read per page.
        $byDate = Cache::remember('missed-punch-popup:'.$employee->id.':'.intdiv(now()->timestamp, 120), 150,
            fn () => collect(app(MissedPunchTracker::class)->between($employee, $today->copy()->subDays(self::LOOKBACK_DAYS)->min(Carbon::parse($weekStart)), $today))
                ->map(fn (array $list) => array_map(fn (array $m) => array_diff_key($m, ['after_at' => true]), $list))
                ->all());

        $seen = array_flip($this->seen());
        $since = $today->copy()->subDays(self::LOOKBACK_DAYS)->toDateString();
        $missed = collect($byDate)
            ->filter(fn (array $list, string $date) => $date >= $since)
            ->flatten(1)
            ->reject(fn (array $m) => $m['resolved'] || isset($seen[$m['key']]))
            ->sortByDesc('date')->values()->all();
        $weekCount = MissedPunchTracker::countInWeek($byDate, Carbon::parse($weekStart));
        $status = MissedPunchTracker::status($weekCount);
        // Seen per level: going over the allowance pops up again after the warning was seen.
        $weekKey = 'week:'.$weekStart.':'.$status;
        $warn = $status !== MissedPunchTracker::STATUS_OK && ! isset($seen[$weekKey]);

        return $missed === [] && ! $warn ? null : [
            'missed' => $missed,
            'week_count' => $weekCount,
            'warn' => $warn,
            'week_key' => $weekKey,
            'status' => $status,
            'allowance' => MissedPunchTracker::allowance(),
            'week_start' => $weekStart,
        ];
    }

    /** @return list<string> */
    private function seen(): array
    {
        return (array) Cache::get($this->seenKey(), []);
    }

    private function seenKey(): string
    {
        return 'missed-punch-popup:seen:'.Auth::id();
    }
}
