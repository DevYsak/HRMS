<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\BreakLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\Approvals\ClaimLockService;
use App\Services\Attendance\PunchTimeline;
use App\Services\AttendanceService;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Livewire\Component;
use Livewire\WithPagination;

class AllAttendance extends Component
{
    use WithPagination;

    public $search = '';

    public $status = '';

    public $date = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        // Default to current week (Mon–Sun)
        $this->dateFrom = Carbon::now()->startOfWeek()->format('Y-m-d');
        $this->dateTo = Carbon::now()->endOfWeek()->format('Y-m-d');
    }

    public function previousWeek(): void
    {
        $this->dateFrom = Carbon::parse($this->dateFrom)->subWeek()->format('Y-m-d');
        $this->dateTo = Carbon::parse($this->dateTo)->subWeek()->format('Y-m-d');
        $this->resetPage();
    }

    public function nextWeek(): void
    {
        $this->dateFrom = Carbon::parse($this->dateFrom)->addWeek()->format('Y-m-d');
        $this->dateTo = Carbon::parse($this->dateTo)->addWeek()->format('Y-m-d');
        $this->resetPage();
    }

    public function thisWeek(): void
    {
        $this->dateFrom = Carbon::now()->startOfWeek()->format('Y-m-d');
        $this->dateTo = Carbon::now()->endOfWeek()->format('Y-m-d');
        $this->resetPage();
    }

    public function exportCsv()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $query = Attendance::query()->with('employee.user', 'employee.office')->whereHas('employee.user');

        $this->applyFilters($query);

        $rows = $query->latest('date')->get();

        $csv = implode(',', ['Employee', 'Employee ID', 'Department', 'Date', 'Check In', 'Check Out', 'Work Hours', 'Status', 'Office'])."\n";

        foreach ($rows as $log) {
            $csv .= implode(',', [
                '"'.($log->employee?->user?->name ?? '').'"',
                '"'.($log->employee?->employee_id ?? '').'"',
                '"'.($log->employee?->department?->name ?? '').'"',
                '"'.($log->date?->format('d M Y') ?? '').'"',
                '"'.($log->check_in?->format('H:i') ?? '').'"',
                '"'.($log->check_out?->format('H:i') ?? '').'"',
                '"'.($log->total_hours ?? '').'"',
                '"'.($log->status ?? '').'"',
                '"'.($log->employee?->office?->name ?? '').'"',
            ])."\n";
        }

        $filename = 'attendance_'.($this->dateFrom ?: 'all').'_to_'.($this->dateTo ?: 'all').'.csv';

        return Response::streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->resetPage();
    }

    public bool $showReviewModal = false;

    public bool $regularisationLocked = false;

    public string $lockedByName = '';

    public $activeRequest = null;

    public string $reviewComment = '';

    // ── HR Mark Attendance ───────────────────────────────────────────────────
    public bool $showMarkModal = false;

    public string $markEmployeeId = '';

    public string $markDate = '';

    public string $markCheckIn = '';

    public string $markCheckOut = '';

    public string $markWorkMode = 'office';

    public string $markReason = '';

    public function openMarkModal(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->reset(['markEmployeeId', 'markDate', 'markCheckIn', 'markCheckOut', 'markReason']);
        $this->markWorkMode = 'office';
        $this->markDate = now()->format('Y-m-d');
        $this->showMarkModal = true;
    }

    public function submitMarkAttendance(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->validate([
            'markEmployeeId' => 'required|exists:employees,id',
            'markDate' => 'required|date|before_or_equal:today',
            'markCheckIn' => 'required|date_format:H:i',
            'markCheckOut' => 'required|date_format:H:i|after:markCheckIn',
            'markReason' => 'required|string|min:5',
        ]);

        $employee = Employee::with(['user', 'manager'])->findOrFail($this->markEmployeeId);
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $this->markDate)
            ->first();

        $regularisation = AttendanceRegularisation::create([
            'employee_id' => $employee->id,
            'attendance_id' => $attendance?->id,
            'work_date' => $this->markDate,
            'requested_check_in' => $this->markDate.' '.$this->markCheckIn.':00',
            'requested_check_out' => $this->markDate.' '.$this->markCheckOut.':00',
            'reason' => '[HR — '.Auth::user()->name.'] '.$this->markReason,
            'status' => 'pending',
        ]);

        // HR marking attendance IS the correction — routing it back through a
        // manager left the employee's hours wrong while HR believed they had
        // fixed them. Applying it through the service (rather than writing the
        // attendance here) keeps one code path for corrections: original punch
        // snapshot, break and late recompute, score rescore, OT filing and the
        // approval trail that records who did it.
        app(AttendanceService::class)->approveRegularisation(
            $regularisation,
            Auth::id(),
            'Applied directly by HR.',
        );

        // Tell the employee their day was corrected — they did not ask for it.
        $employee->user?->notify(new AttendanceRegularisationNotification(
            $employee->user->name,
            Carbon::parse($this->markDate)->format('d M Y'),
            'approved',
        ));

        $this->showMarkModal = false;
        \Flux::toast("Attendance updated for {$employee->user->name} and recorded against your name.");
    }

    // ── Employee 360 Drawer ──────────────────────────────────────────────────
    public ?int $drawerEmployeeId = null;

    /** @var array<string, mixed> */
    public array $drawer = [];

    public function openEmployeeDrawer(int $employeeId): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $employee = Employee::with(['user', 'department', 'jobTitle', 'manager', 'office', 'shift'])
            ->findOrFail($employeeId);

        abort_unless(Auth::user()->coversEmployee($employee), 403);

        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $month = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$monthStart->toDateString(), $today->toDateString()])
            ->orderByDesc('date')
            ->get();

        $workDays = max(1, AttendanceSetting::workingDaysBetween($monthStart, $today));
        $present = $month->whereNotNull('check_in')->count();
        $late = $month->where('is_late', true)->count();
        $onTimePct = $present > 0 ? round(($present - $late) / $present * 100) : 100;
        $presentPct = min(100, round($present / $workDays * 100));

        $todayAtt = $month->firstWhere(fn ($a) => $a->date->isToday());
        $stdMin = (int) round((float) ($employee->shift->standard_hours ?? 9) * 60);
        $onBreak = $todayAtt
            ? BreakLog::where('attendance_id', $todayAtt->id)->whereNull('break_end')->exists()
            : false;

        $rawPunches = AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', $today->toDateString())
            ->orderBy('punched_at')
            ->get();

        // ONE processed timeline from the shared PunchTimeline engine — the
        // single source of truth the employee page also renders from. It merges
        // duplicates/device conflicts, pairs validated sessions and annotates
        // every raw event for the audit view. Worked/break come from these
        // validated sessions, NOT the engine's summary (which mis-pairs this
        // device). The summary is only the fallback when no punches synced.
        $summary = AttendanceDailySummary::where('employee_id', $employee->id)
            ->whereDate('date', $today->toDateString())
            ->first();
        $engineSynced = $summary && $summary->synced_at;
        $processed = app(PunchTimeline::class)->process($rawPunches, $today, $summary);

        if ($rawPunches->isNotEmpty()) {
            $stillInside = $processed['live'];
            $workedMin = (int) $processed['working_minutes'];
            $breakMin = (int) $processed['break_minutes'];
            $todayIn = $processed['first_in'];
            $todayOut = $processed['last_out'];
            // Show the engine's punch count when it's higher (a sync gap), so the
            // "partial" note still fires; otherwise the count we actually paired.
            $punchCount = max((int) $processed['raw_count'], $engineSynced ? (int) $summary->raw_punch_count : 0);
        } elseif ($engineSynced) {
            $stillInside = ((int) $summary->raw_punch_count) % 2 === 1;
            $workedMin = (int) round((float) $summary->working_hours * 60);
            $breakMin = (int) $summary->break_minutes;
            $todayIn = $summary->first_punch?->format('h:i A');
            $todayOut = $stillInside ? null : $summary->last_punch?->format('h:i A');
            $punchCount = (int) $summary->raw_punch_count;
        } else {
            $stillInside = false;
            $breakMin = (int) ($todayAtt->break_minutes ?? 0);
            $workedMin = 0;
            if ($todayAtt?->check_in) {
                $workedMin = max(0, (int) $todayAtt->check_in->diffInMinutes($todayAtt->check_out ?? now()) - $breakMin);
            }
            $todayIn = $todayAtt?->check_in?->format('h:i A');
            $todayOut = $todayAtt?->check_out?->format('h:i A');
            $punchCount = 0;
        }

        $this->drawer = [
            'name' => $employee->user?->name ?? '—',
            'photo' => $employee->photo,
            'code' => $employee->employee_id ?? ($employee->employee_code ? 'PIN '.$employee->employee_code : '—'),
            'department' => $employee->department?->name ?? '—',
            'designation' => $employee->jobTitle?->name ?? $employee->jobTitle?->title ?? '—',
            'manager' => $employee->manager?->name ?? '—',
            'office' => $employee->office?->name ?? '—',
            'attendance_pct' => $presentPct,
            'on_time_pct' => $onTimePct,
            'score' => (int) round($onTimePct * 0.6 + $presentPct * 0.4),
            'late_count' => $late,
            'leave_balance' => LeaveBalance::where('employee_id', $employee->id)
                ->where('year', now()->year)->get()
                ->sum(fn ($b) => $b->available() + (float) ($b->comp_off_credits ?? 0)),
            'status' => $onBreak
                ? 'On Break'
                : ($stillInside
                    ? 'Working'
                    : ($todayOut ? 'Completed' : (($punchCount > 0 || $todayAtt) ? 'Working' : 'Not In'))),
            'today' => ($todayAtt || $engineSynced || $rawPunches->isNotEmpty()) ? [
                'in' => $todayIn,
                'out' => $todayOut,
                'worked' => intdiv($workedMin, 60).'h '.($workedMin % 60).'m',
                'break' => $breakMin,
                'overtime' => ($otMin = max(0, $workedMin - $stdMin)) > 0
                    ? intdiv($otMin, 60).'h '.($otMin % 60).'m'
                    : '0m',
                'mode' => $todayAtt?->work_mode ?? 'office',
                'is_late' => (bool) ($todayAtt?->is_late ?? ($engineSynced && $summary->late_minutes > 0)),
                'device' => $rawPunches->pluck('device_serial')->filter()->unique()->implode(', ') ?: ($engineSynced ? $summary->device_serial : null),
                'location' => $rawPunches->pluck('location')->filter()->unique()->implode(', ') ?: ($employee->office?->name ?? null),
            ] : null,
            'punch_count' => $punchCount,
            'punches_partial' => $engineSynced && $rawPunches->count() < $punchCount,
            // Processed timeline (what employees see) — validated IN/OUT only.
            'punches' => collect($processed['nodes'])->map(fn ($n) => [
                'time' => $n['time'],
                'dir' => $n['dir'],
                'type' => $n['type'],
                'method' => $n['method_label'],
                'icon' => $n['method_icon'] ?? 'clock',
            ])->all(),
            'sessions' => $processed['sessions'],
            'duplicate_count' => (int) $processed['duplicate_count'],
            'conflict_count' => (int) $processed['conflict_count'],
            'needs_regularization' => (bool) $processed['needs_regularization'],
            // Raw device log (HR/admin audit only) — every event, annotated.
            'raw_punches' => $processed['raw_events'],
            'late_history' => $month->where('is_late', true)->take(5)->map(fn ($a) => [
                'date' => $a->date->format('d M'), 'mins' => (int) ($a->late_minutes ?? 0),
            ])->values()->all(),
            'break_history' => $month->filter(fn ($a) => (int) ($a->break_minutes ?? 0) > 0)->take(5)->map(fn ($a) => [
                'date' => $a->date->format('d M'), 'mins' => (int) $a->break_minutes,
            ])->values()->all(),
            'history' => $month->take(7)->map(fn ($a) => [
                'date' => $a->date->format('d M'),
                'in' => $a->check_in?->format('H:i'),
                'out' => $a->check_out?->format('H:i'),
                'hours' => $a->total_hours,
                'status' => $a->status,
            ])->values()->all(),
            // Week-by-week rollup (this month) for the drawer's Weekly tab.
            'weekly_history' => $month
                ->groupBy(fn ($a) => $a->date->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'))
                ->map(function ($rows, $weekStart) {
                    $ws = Carbon::parse($weekStart);

                    return [
                        'label' => $ws->format('d M').' – '.$ws->copy()->addDays(6)->format('d M'),
                        'present' => $rows->whereNotNull('check_in')->count(),
                        'hours' => round($rows->sum(fn ($a) => (float) $a->total_hours), 1),
                        'late' => $rows->where('is_late', true)->count(),
                    ];
                })->sortKeysDesc()->values()->take(6)->all(),
            'pending' => AttendanceRegularisation::where('employee_id', $employee->id)
                ->where('status', 'pending')->with('claimer')->orderByDesc('work_date')->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'date' => Carbon::parse($r->work_date)->format('d M Y'),
                    'window' => Carbon::parse($r->requested_check_in)->format('H:i').' → '.Carbon::parse($r->requested_check_out)->format('H:i'),
                    'reason' => $r->reason,
                    'stage' => $r->stageLabel(),
                    'handled_by' => app(ClaimLockService::class)->heldByOther($r, Auth::id()) ? $r->claimer?->name : null,
                ])->all(),
        ];
        $this->drawerEmployeeId = $employeeId;
    }

    public function closeDrawer(): void
    {
        $this->drawerEmployeeId = null;
        $this->drawer = [];
    }

    /** One-click approve from the drawer; rejection goes via the review modal (comment required). */
    public function quickApproveRegularisation(int $id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $request = AttendanceRegularisation::with('employee.user')->findOrFail($id);
        abort_unless($request->employee && Auth::user()->coversEmployee($request->employee), 403);
        if ($request->status !== 'pending') {
            return;
        }

        // Claim-lock: another HR is actively handling this request.
        if (app(ClaimLockService::class)->heldByOther($request, Auth::id())) {
            \Flux::toast('Being handled by '.($request->claimer?->name ?? 'another HR admin').' — no action needed.', variant: 'warning');

            return;
        }

        $attendance = app(AttendanceService::class)->approveRegularisation($request, Auth::id());
        app(ClaimLockService::class)->release($request);
        $request->refresh();

        if ($attendance) {
            AuditLog::record($attendance, 'regularised', $attendance->toArray(), null);
            \Flux::toast('Regularisation approved — hours & attendance updated.');
        } else {
            \Flux::toast($request->status === 'pending'
                ? 'Approved at your stage — now awaiting '.$request->stageLabel().'.'
                : 'Regularisation updated.');
        }
        $request->employee->user?->notify(new RegularisationReviewedNotification($request));

        if ($this->drawerEmployeeId) {
            $this->openEmployeeDrawer($this->drawerEmployeeId); // refresh drawer data
        }
    }

    public function openReviewModal(int $id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->activeRequest = AttendanceRegularisation::with('employee.user', 'attendance', 'reviewer', 'claimer')->findOrFail($id);
        abort_unless($this->activeRequest->employee && Auth::user()->coversEmployee($this->activeRequest->employee), 403);

        // Claim-lock: first in-scope HR to open a pending request claims it;
        // anyone else is told who's on it instead of getting the modal.
        if ($this->activeRequest->status === 'pending') {
            if (! app(ClaimLockService::class)->claim($this->activeRequest, Auth::id())) {
                \Flux::toast('Being handled by '.($this->activeRequest->claimer?->name ?? 'another HR admin').' — no action needed.', variant: 'warning');
                $this->activeRequest = null;

                return;
            }
        }

        $this->reviewComment = '';
        $this->regularisationLocked = false;
        $this->lockedByName = '';

        // Warn HR if this was previously approved by Super Admin
        if (
            $this->activeRequest->status === 'approved'
            && $this->activeRequest->reviewer
            && $this->activeRequest->reviewer->isSuperAdmin()
            && Auth::user()->isHrAdmin()
        ) {
            $this->regularisationLocked = true;
            $this->lockedByName = $this->activeRequest->reviewer->name;
        }

        $this->showReviewModal = true;
    }

    public function approveRegularisation()
    {
        if (! $this->activeRequest) {
            return;
        }

        $attendance = app(AttendanceService::class)->approveRegularisation(
            $this->activeRequest,
            Auth::id(),
            $this->reviewComment ?: null,
        );
        $this->activeRequest->refresh();

        if ($attendance) {
            AuditLog::record($attendance, 'regularised', $attendance->toArray(), null);
            \Flux::toast('Regularisation request approved.');
        } else {
            \Flux::toast($this->activeRequest->status === 'pending'
                ? 'Approved at your stage — now awaiting '.$this->activeRequest->stageLabel().'.'
                : 'Regularisation updated.');
        }

        $this->activeRequest->employee->user->notify(new RegularisationReviewedNotification($this->activeRequest));

        app(ClaimLockService::class)->release($this->activeRequest);
        $this->showReviewModal = false;
        $this->activeRequest = null;
    }

    /** Close the review modal without deciding — releases this HR's claim. */
    public function closeReviewModal(): void
    {
        if ($this->activeRequest && (int) $this->activeRequest->claimed_by === Auth::id()) {
            app(ClaimLockService::class)->release($this->activeRequest);
        }

        $this->showReviewModal = false;
        $this->activeRequest = null;
    }

    public function rejectRegularisation(): void
    {
        if (! $this->activeRequest) {
            return;
        }

        // HR cannot override Super Admin approved regularisation without a comment
        if ($this->regularisationLocked && empty(trim($this->reviewComment))) {
            $this->addError('reviewComment', 'A comment is required to override a Super Admin approved regularisation.');

            return;
        }

        $this->validate(['reviewComment' => 'required|string|min:5']);

        app(AttendanceService::class)->rejectRegularisation($this->activeRequest, Auth::id(), $this->reviewComment);

        $this->activeRequest->employee->user->notify(new RegularisationReviewedNotification($this->activeRequest));

        app(ClaimLockService::class)->release($this->activeRequest);
        $this->showReviewModal = false;
        $this->activeRequest = null;
        \Flux::toast('Regularisation request rejected.');
    }

    /**
     * Working days (excluding Sundays) covered by the active filter — 1 for a
     * single-day filter, the span for a range, capped at today so future days
     * don't inflate the "expected" denominator.
     */
    protected function filteredWorkingDays(): int
    {
        $today = Carbon::today();

        if ($this->date) {
            return AttendanceSetting::isWeeklyOff(Carbon::parse($this->date)) ? 0 : 1;
        }

        if ($this->dateFrom && $this->dateTo) {
            $from = Carbon::parse($this->dateFrom);
            $to = Carbon::parse($this->dateTo)->min($today);
            if ($to->lt($from)) {
                return 0;
            }

            return AttendanceSetting::workingDaysBetween($from, $to);
        }

        return 1;
    }

    /** @param Builder<Attendance> $query */
    protected function applyFilters($query): void
    {
        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('employee.user', fn ($q2) => $q2->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('employee', fn ($q2) => $q2->where('employee_id', 'like', '%'.$search.'%'));
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->date) {
            $query->where('date', $this->date);
        } elseif ($this->dateFrom || $this->dateTo) {
            if ($this->dateFrom) {
                $query->where('date', '>=', $this->dateFrom);
            }
            if ($this->dateTo) {
                $query->where('date', '<=', $this->dateTo);
            }
        }
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        // Department/shift scope: HR with a scope only sees their employees.
        $scopeIds = Auth::user()->accessibleEmployeeIds();
        $scoped = fn ($q) => $q->when($scopeIds !== null, fn ($qq) => $qq->whereIn('employee_id', $scopeIds));

        // Exclude offboarded (inactive/archived) employees from the live roster —
        // their biometric card is released on exit and they should not surface here.
        $query = Attendance::query()->with('employee.user')
            ->whereHas('employee.user')
            ->whereHas('employee', fn ($q) => $q->whereNotIn('status', ['inactive', 'archived']));
        $scoped($query);

        $this->applyFilters($query);

        $pendingRegularisations = $scoped(
            AttendanceRegularisation::where('status', 'pending')
                ->with(['employee.user', 'attendance'])
                ->whereHas('employee.user')
        )->get();

        // KPI stats recompute from the SAME filtered dataset the table shows —
        // not a fixed "today" snapshot. Change the date/range/status/search and
        // every card recomputes (present, absent, late, on-time, WFH).
        $totalActive = Employee::where('status', 'active')
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('id', $scopeIds))
            ->count();

        $filtered = (clone $query)->get(['employee_id', 'date', 'check_in', 'is_late', 'work_mode']);

        // A present "slot" is one employee-day with a check-in.
        $present = $filtered->whereNotNull('check_in')
            ->unique(fn ($a) => $a->employee_id.'|'.$a->date->toDateString())
            ->count();
        $late = $filtered->where('is_late', true)->count();
        $onTime = max(0, $present - $late);
        $wfh = $filtered->whereIn('work_mode', ['wfh', 'hybrid'])->count();

        // Expected slots = active employees × working days in the filtered span,
        // so "Absent" is meaningful for a single day and for a range.
        $workingDays = $this->filteredWorkingDays();
        $expectedSlots = $totalActive * $workingDays;
        $absent = max(0, $expectedSlots - $present);

        $stats = [
            'total' => $totalActive,
            'present' => $present,
            'absent' => $absent,
            'on_time' => $onTime,
            'late' => $late,
            'wfh' => $wfh,
            'present_pct' => $expectedSlots > 0 ? round(($present / $expectedSlots) * 100, 1) : 0,
            'absent_pct' => $expectedSlots > 0 ? round(($absent / $expectedSlots) * 100, 1) : 0,
            'late_pct' => $present > 0 ? round(($late / $present) * 100, 1) : 0,
        ];

        // Presence trend across the selected range (drives the overview chart).
        $trend = [];
        if ($this->dateFrom && $this->dateTo) {
            $byDay = $scoped(Attendance::whereBetween('date', [$this->dateFrom, $this->dateTo]))
                ->get(['date', 'is_late', 'employee_id'])
                ->groupBy(fn ($a) => $a->date->toDateString());
            foreach (CarbonPeriod::create($this->dateFrom, $this->dateTo) as $d) {
                $day = $byDay->get($d->toDateString(), collect());
                $trend[] = [
                    'label' => $d->format('d M'),
                    'present' => $day->count(),
                    'late' => $day->where('is_late', true)->count(),
                ];
            }
        }

        $weekLabel = $this->dateFrom && $this->dateTo
            ? Carbon::parse($this->dateFrom)->format('d M').' – '.Carbon::parse($this->dateTo)->format('d M Y')
            : 'All dates';

        return view('livewire.attendance.all-attendance', [
            'attendances' => $query->latest('date')->paginate(15),
            'pendingRegularisations' => $pendingRegularisations,
            'allEmployees' => Employee::with('user')->whereHas('user')
                ->when($scopeIds !== null, fn ($q) => $q->whereIn('id', $scopeIds))
                ->orderBy('id')->get(),
            'stats' => $stats,
            'trend' => $trend,
            'weekLabel' => $weekLabel,
        ])->layout('layouts.app', ['title' => 'Employee Attendance']);
    }
}
