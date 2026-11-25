<?php

namespace App\Livewire\Attendance;

use App\Models\Department;
use App\Services\Attendance\AttendanceRegister;
use App\Services\Attendance\LiveAttendanceService;
use App\Services\Security\ScopeResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Live Attendance — for HR, Admin and anyone else holding
 * view_live_attendance, over the people its scope reaches:
 *   - Live         who is working now (session from their latest IN, worked
 *                  against the shift's hours), who is out and for how long,
 *                  and who is away — by department, refreshed every 10–60s;
 *   - Activity     today's punches as they happen;
 *   - By Day       everyone's punches on one date, with each missed punch;
 *   - By Employee  one person day by day over a range, with totals;
 *   - Punch Audit  every exception over a range (missed punches, late,
 *                  missing / auto check-outs, long breaks, absences,
 *                  regularised days, extra taps).
 * Figures come from LiveAttendanceService and AttendanceRegister, which read
 * the canonical attendance services; nothing is calculated here.
 */
class LiveAttendance extends Component
{
    public const TABS = ['live' => 'Live', 'activity' => 'Activity', 'day' => 'By Day', 'employee' => 'By Employee', 'audit' => 'Punch Audit'];

    /** Seconds between refreshes on Live and Activity; 0 = off. */
    public const REFRESH = [10, 30, 60, 0];

    public const PRESETS = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', '7d' => 'Last 7 days',
        'month' => 'This month', 'last_month' => 'Last month', '30d' => 'Last 30 days'];

    public const MAX_ACTIVITY = 100;

    #[Url]
    public string $tab = 'live';

    #[Url]
    public string $department = '';

    #[Url]
    public string $shift = '';

    /** Live: a board filter (LiveAttendanceService::STATUSES). */
    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url(except: 'attention')]
    public string $sort = 'attention';

    #[Url(except: 'department')]
    public string $group = 'department';

    #[Url(except: 30)]
    public int $refresh = 30;

    /** list | grid */
    #[Url(except: 'list')]
    public string $layout = 'list';

    public bool $showAway = true;

    /** By Day: the date shown. */
    #[Url]
    public string $date = '';

    /** By Day: AttendanceRegister::DAY_STATUSES. */
    #[Url]
    public string $dayStatus = '';

    /** By Employee and Punch Audit: the range. */
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url(as: 'employee')]
    public ?int $employeeId = null;

    /** Punch Audit: AttendanceRegister::AUDIT_TYPES. */
    #[Url]
    public string $auditType = '';

    /** Latest activity shown (20, then "View more activity"). */
    public int $activityLimit = 20;

    public function mount(): void
    {
        $this->authorizeView();

        // Earlier tab names, still in saved links.
        $this->tab = match ($this->tab) {
            'overview', 'status' => 'live',
            default => array_key_exists($this->tab, self::TABS) ? $this->tab : 'live',
        };
        $this->refresh = in_array($this->refresh, self::REFRESH, true) ? $this->refresh : 30;
        $this->date = $this->validDate($this->date, Carbon::today())->toDateString();
        $this->from = $this->validDate($this->from, Carbon::today()->subDays(6))->toDateString();
        $this->to = $this->validDate($this->to, Carbon::today())->toDateString();
    }

    public function refreshNow(): void
    {
        // A render is the refresh; the data is rebuilt in render().
    }

    public function viewMoreActivity(): void
    {
        $this->activityLimit = min(self::MAX_ACTIVITY, $this->activityLimit + 30);
        $this->tab = 'activity';
    }

    /** A board filter chip; the same chip again clears it. */
    public function setStatus(string $status): void
    {
        $this->status = $this->status === $status || ! array_key_exists($status, LiveAttendanceService::STATUSES) ? '' : $status;
    }

    /** "Needs attention" links apply the matching filter on the board. */
    public function applyAttention(string $status): void
    {
        $this->status = array_key_exists($status, LiveAttendanceService::STATUSES) ? $status : '';
        $this->tab = 'live';
    }

    public function setDepartment(?int $departmentId = null): void
    {
        $this->department = $departmentId ? (string) $departmentId : '';
    }

    public function updatedStatus(): void
    {
        if (! array_key_exists($this->status, LiveAttendanceService::STATUSES)) {
            $this->status = '';
        }
    }

    public function updatedRefresh(): void
    {
        $this->refresh = in_array((int) $this->refresh, self::REFRESH, true) ? (int) $this->refresh : 30;
    }

    public function updatedDate(): void
    {
        $this->date = $this->validDate($this->date, Carbon::today())->toDateString();
    }

    public function updatedFrom(): void
    {
        $this->from = $this->validDate($this->from, Carbon::today()->subDays(6))->toDateString();
    }

    public function updatedTo(): void
    {
        $this->to = $this->validDate($this->to, Carbon::today())->toDateString();
    }

    public function shiftDate(int $days): void
    {
        $this->date = Carbon::parse($this->date)->addDays($days)->min(Carbon::today())->toDateString();
    }

    public function setDayStatus(string $status): void
    {
        $this->dayStatus = $this->dayStatus === $status || ! array_key_exists($status, AttendanceRegister::DAY_STATUSES) ? '' : $status;
    }

    public function setAuditType(string $type): void
    {
        $this->auditType = $this->auditType === $type || ! array_key_exists($type, AttendanceRegister::AUDIT_TYPES) ? '' : $type;
    }

    public function preset(string $preset): void
    {
        $today = Carbon::today();
        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'week' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today],
            'month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            '30d' => [$today->copy()->subDays(29), $today],
            default => [$today->copy()->subDays(6), $today],
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /** A person's days, from the board or a list. */
    public function openEmployee(int $employeeId, ?string $date = null): void
    {
        $this->employeeId = $employeeId;
        $this->tab = 'employee';

        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $day = Carbon::parse($date);
            if ($day->lt(Carbon::parse($this->from)) || $day->gt(Carbon::parse($this->to))) {
                $this->from = $day->copy()->subDays(6)->toDateString();
                $this->to = $day->copy()->addDays(6)->min(Carbon::today())->toDateString();
            }
        }
    }

    /** Everyone's punches on a date. */
    public function openDay(string $date): void
    {
        $this->date = $this->validDate($date, Carbon::today())->toDateString();
        $this->tab = 'day';
    }

    public function render(LiveAttendanceService $live, AttendanceRegister $register): View
    {
        // Every request (including each poll) — access can be withdrawn mid-session.
        $this->authorizeView();
        $viewer = Auth::user();
        $filters = ['department' => $this->department, 'shift' => $this->shift, 'search' => $this->search];
        $data = [];

        if (in_array($this->tab, ['live', 'activity'], true)) {
            $data = $live->snapshot($viewer, $filters + ['status' => $this->status, 'sort' => $this->sort, 'group' => $this->group], $this->activityLimit);
        } else {
            $visible = $live->visibleEmployees($viewer);
            $data = [
                'departments' => Department::whereIn('id', $visible->pluck('department_id')->filter()->unique())->orderBy('name')->get(['id', 'name']),
                'people' => $visible->sortBy(fn ($e) => mb_strtolower((string) $e->user?->name))->values(),
                'scope_label' => app(ScopeResolver::class)->scopeFor($viewer, LiveAttendanceService::PERMISSION)->label(),
                'now_ms' => (int) now()->getTimestampMs(),
            ];
            $data += match ($this->tab) {
                'day' => ['register' => $register->day($viewer, Carbon::parse($this->date), $filters + ['status' => $this->dayStatus])],
                'employee' => ['register' => $this->employeeId ? $register->employee($viewer, $this->employeeId, Carbon::parse($this->from), Carbon::parse($this->to)) : null],
                default => ['register' => $register->audit($viewer, Carbon::parse($this->from), Carbon::parse($this->to), $filters + ['type' => $this->auditType])],
            };
        }

        return view('livewire.attendance.live-attendance', $data + [
            'syncedAt' => now(),
            'statusOptions' => LiveAttendanceService::STATUSES,
        ])->layout('layouts.app', ['title' => 'Live Attendance']);
    }

    private function validDate(string $value, Carbon $default): Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || strtotime($value) === false) {
            return $default;
        }

        return Carbon::parse($value)->min(Carbon::today());
    }

    private function authorizeView(): void
    {
        abort_unless(Auth::user()?->hasPermission(LiveAttendanceService::PERMISSION), 403);
    }
}
