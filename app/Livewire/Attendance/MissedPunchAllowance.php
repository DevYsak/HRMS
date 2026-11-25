<?php

namespace App\Livewire\Attendance;

use App\Models\AttendanceRegularisation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MissedPunchFlag;
use App\Services\Attendance\MissedPunchTracker;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Attendance → Missed Punches (HR): every employee's missed punches in one
 * calendar month (its Mon–Fri days) against the monthly allowance, who is
 * over it and flagged, the regularisation requests they made, and HR's review
 * of each flag — plus the flag history. Only the people the viewer's
 * attendance reach covers.
 */
class MissedPunchAllowance extends Component
{
    public const PERMISSION = 'manage_attendance';

    /** The month shown, "Y-m". */
    #[Url(except: '')]
    public string $month = '';

    #[Url(as: 'department', except: null)]
    public ?int $departmentId = null;

    #[Url(except: 'all')]
    public string $status = 'all';

    public string $search = '';

    public ?int $expanded = null;

    public ?int $reviewFlagId = null;

    public string $reviewNote = '';

    public function mount(): void
    {
        $this->authorizeView();
        $this->month = $this->monthStart()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonthNoOverflow()->format('Y-m');
        $this->expanded = null;
    }

    public function nextMonth(): void
    {
        $next = $this->monthStart()->addMonthNoOverflow();
        $this->month = ($next->gt(Carbon::today()) ? Carbon::today() : $next)->format('Y-m');
        $this->expanded = null;
    }

    public function thisMonth(): void
    {
        $this->month = Carbon::today()->format('Y-m');
        $this->expanded = null;
    }

    public function toggle(int $employeeId): void
    {
        $this->expanded = $this->expanded === $employeeId ? null : $employeeId;
    }

    public function openReview(int $flagId): void
    {
        $this->authorizeView();
        $flag = MissedPunchFlag::with('employee')->findOrFail($flagId);
        abort_unless($flag->employee && Auth::user()->coversEmployee($flag->employee), 403);

        $this->reviewFlagId = $flag->id;
        $this->reviewNote = (string) $flag->review_note;
        $this->resetErrorBag();
        $this->modal('missed-punch-review')->show();
    }

    /** HR marks a flag reviewed, with a note — audited. */
    public function saveReview(): void
    {
        $this->authorizeView();
        $this->validate(['reviewNote' => ['required', 'string', 'min:3', 'max:2000']], [], ['reviewNote' => 'review note']);

        $flag = MissedPunchFlag::with('employee')->findOrFail($this->reviewFlagId);
        abort_unless($flag->employee && Auth::user()->coversEmployee($flag->employee), 403);

        $old = ['reviewed_at' => $flag->reviewed_at?->toIso8601String(), 'review_note' => $flag->review_note];
        $flag->update(['reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'review_note' => trim($this->reviewNote)]);

        app(AuditService::class)->event('ATTENDANCE_MISSED_PUNCH_REVIEWED', AuditService::ATTENDANCE, $flag,
            old: $old, new: ['reviewed_at' => $flag->reviewed_at->toIso8601String(), 'review_note' => $flag->review_note],
            subjectEmployeeId: $flag->employee_id);

        $this->reviewFlagId = null;
        $this->reviewNote = '';
        $this->modal('missed-punch-review')->close();
        \Flux::toast('Marked reviewed.', variant: 'success');
    }

    public function render(MissedPunchTracker $tracker)
    {
        $this->authorizeView();

        $start = $this->monthStart();
        $monthEnd = $start->copy()->endOfMonth();
        $end = $monthEnd->copy()->min(Carbon::today());
        $reach = Auth::user()->accessibleEmployeeIds(self::PERMISSION);
        $allowance = MissedPunchTracker::allowance();

        $employees = Employee::query()->with(['user', 'department'])
            ->whereHas('user')->where('status', 'active')
            ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))
            ->when($this->departmentId, fn ($q) => $q->where('department_id', $this->departmentId))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.trim($this->search).'%'))
                ->orWhere('employee_id', 'like', '%'.trim($this->search).'%')))
            ->get();

        $flags = MissedPunchFlag::with('reviewer')->where('period', MissedPunchFlag::PERIOD_MONTH)->whereDate('period_start', $start->toDateString())
            ->whereIn('employee_id', $employees->pluck('id'))->get()->keyBy('employee_id');
        $requests = AttendanceRegularisation::with('reviewer')->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('work_date', [$start->toDateString(), $monthEnd->toDateString()])
            ->orderBy('work_date')->get()->groupBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($tracker, $start, $end, $flags, $requests) {
            $byDate = $start->lte($end) ? $tracker->between($employee, $start, $end) : [];
            $count = MissedPunchTracker::countInMonth($byDate, $start);

            return [
                'employee' => $employee,
                'count' => $count,
                'status' => MissedPunchTracker::status($count),
                'missed' => collect($byDate)->flatten(1)->sortBy('date')->values()->all(),
                'flag' => $flags->get($employee->id),
                'requests' => $requests->get($employee->id, collect()),
            ];
        })
            ->filter(fn (array $r) => match ($this->status) {
                'missed' => $r['count'] > 0,
                'warning' => $r['status'] !== MissedPunchTracker::STATUS_OK,
                'exceeded' => $r['status'] === MissedPunchTracker::STATUS_EXCEEDED,
                'open' => $r['flag'] !== null && ! $r['flag']->isReviewed(),
                default => true,
            })
            ->sortBy([['count', 'desc'], fn (array $a, array $b) => strcmp($a['employee']->user->name, $b['employee']->user->name)])
            ->values();

        $history = MissedPunchFlag::with(['employee.user', 'employee.department', 'reviewer'])
            ->when($reach !== null, fn ($q) => $q->whereIn('employee_id', $reach))
            ->when($this->departmentId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $this->departmentId)))
            ->latest('period_start')->latest('id')->limit(50)->get();

        return view('livewire.attendance.missed-punch-allowance', [
            'rows' => $rows,
            'history' => $history,
            'allowance' => $allowance,
            'warningAt' => MissedPunchTracker::warningAt(),
            'monthLabel' => MissedPunchTracker::monthLabel($start),
            'isThisMonth' => $start->isSameDay(MissedPunchTracker::monthStart(Carbon::today())),
            'kpis' => [
                'missed' => $rows->where('count', '>', 0)->count(),
                'warning' => $rows->where('status', MissedPunchTracker::STATUS_WARNING)->count(),
                'exceeded' => $rows->where('status', MissedPunchTracker::STATUS_EXCEEDED)->count(),
                'open' => $rows->filter(fn (array $r) => $r['flag'] !== null && ! $r['flag']->isReviewed())->count(),
            ],
            'departments' => Department::query()
                ->when($reach !== null, fn ($q) => $q->whereIn('id', Employee::whereIn('id', $reach)->whereNotNull('department_id')->distinct()->pluck('department_id')))
                ->orderBy('name')->get(['id', 'name']),
            'reviewing' => $this->reviewFlagId ? MissedPunchFlag::with('employee.user')->find($this->reviewFlagId) : null,
        ])->layout('layouts.app', ['title' => 'Missed Punches']);
    }

    /** The 1st of the month shown; this month for a missing or bad value, never a future month. */
    private function monthStart(): Carbon
    {
        $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)
            ? Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay()
            : Carbon::today()->startOfMonth();

        return $start->min(Carbon::today()->startOfMonth());
    }

    /** HR / attendance managers: manage_attendance, over the people it reaches. */
    private function authorizeView(): void
    {
        abort_unless(Auth::user()?->hasPermission(self::PERMISSION), 403);
    }
}
