<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\BreakLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\Attendance\PunchClassifier;
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

        AttendanceRegularisation::create([
            'employee_id' => $employee->id,
            'attendance_id' => $attendance?->id,
            'work_date' => $this->markDate,
            'requested_check_in' => $this->markDate.' '.$this->markCheckIn.':00',
            'requested_check_out' => $this->markDate.' '.$this->markCheckOut.':00',
            'reason' => '[HR — '.Auth::user()->name.'] '.$this->markReason,
            'status' => 'pending',
        ]);

        // Notify manager for approval; fallback to HR team if no manager
        $notification = new AttendanceRegularisationNotification(
            $employee->user->name,
            Carbon::parse($this->markDate)->format('d M Y'),
            'pending',
        );

        if ($employee->manager) {
            $employee->manager->notify($notification);
        } else {
            User::whereIn('role', ['hr_admin', 'super_admin'])
                ->each(fn ($u) => $u->notify($notification));
        }

        $this->showMarkModal = false;
        \Flux::toast("Attendance regularisation submitted for {$employee->user->name}. Pending manager approval.");
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

        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $month = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$monthStart->toDateString(), $today->toDateString()])
            ->orderByDesc('date')
            ->get();

        $workDays = max(1, (int) $monthStart->diffInDaysFiltered(fn ($d) => ! $d->isSunday(), $today->copy()->endOfDay()));
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
        $classifier = app(PunchClassifier::class);
        // The engine already sends its real, paired punch stream, so the drawer
        // lists every punch verbatim — applying HRMS's noise dedup here would
        // hide legitimate session-boundary punches (e.g. a 16:36 IN two minutes
        // after a 16:34 OUT). Dedup is only used for the no-engine break fallback.
        $punches = $rawPunches;

        // The Python engine pairs real IN/OUT direction and is the source of
        // truth for today's figures. Trust its synced daily summary over
        // re-deriving from HRMS's local — and often incomplete — punch stream
        // (alternation-based math mis-pairs when punches are missing). An odd
        // engine punch count means the last punch is an IN → still inside.
        $summary = AttendanceDailySummary::where('employee_id', $employee->id)
            ->whereDate('date', $today->toDateString())
            ->first();
        $engineSynced = $summary && $summary->synced_at;
        $stillInside = $engineSynced && ((int) $summary->raw_punch_count) % 2 === 1;

        if ($engineSynced) {
            $workedMin = (int) round((float) $summary->working_hours * 60);
            $breakMin = (int) $summary->break_minutes;
            $todayIn = $summary->first_punch?->format('h:i A');
            $todayOut = $stillInside ? null : $summary->last_punch?->format('h:i A');
            $punchCount = (int) $summary->raw_punch_count;
        } else {
            $breakMin = $rawPunches->isNotEmpty()
                ? $classifier->breakMinutes($rawPunches)
                : (int) ($todayAtt->break_minutes ?? 0);
            $workedMin = 0;
            if ($todayAtt?->check_in) {
                $workedMin = max(0, (int) $todayAtt->check_in->diffInMinutes($todayAtt->check_out ?? now()) - $breakMin);
            }
            $todayIn = $todayAtt?->check_in?->format('h:i A');
            $todayOut = $todayAtt?->check_out?->format('h:i A');
            $punchCount = $punches->count();
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
                : ($engineSynced
                    ? ($stillInside ? 'Working' : ($todayOut ? 'Completed' : ($punchCount > 0 ? 'Working' : 'Not In')))
                    : ($todayAtt?->check_out ? 'Completed' : ($todayAtt ? 'Working' : 'Not In'))),
            'today' => ($todayAtt || $engineSynced) ? [
                'in' => $todayIn,
                'out' => $todayOut,
                'worked' => intdiv($workedMin, 60).'h '.($workedMin % 60).'m',
                'break' => $breakMin,
                'overtime' => ($otMin = $engineSynced ? (int) $summary->overtime_minutes : max(0, $workedMin - $stdMin)) > 0
                    ? intdiv($otMin, 60).'h '.($otMin % 60).'m'
                    : '0m',
                'mode' => $todayAtt?->work_mode ?? 'office',
                'is_late' => (bool) ($todayAtt?->is_late ?? ($engineSynced && $summary->late_minutes > 0)),
                'device' => $punches->pluck('device_serial')->filter()->unique()->implode(', ') ?: ($engineSynced ? $summary->device_serial : null),
                'location' => $punches->pluck('location')->filter()->unique()->implode(', ') ?: ($employee->office?->name ?? null),
            ] : null,
            'punch_count' => $punchCount,
            'punches_partial' => $engineSynced && $punches->count() < $punchCount,
            'punches' => $punches->map(fn ($p) => [
                'time' => $p->punched_at->format('h:i A'),
                'method' => $p->methodEnum()?->label(),
                'icon' => $p->methodEnum()?->icon() ?? 'clock',
            ])->all(),
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
            'pending' => AttendanceRegularisation::where('employee_id', $employee->id)
                ->where('status', 'pending')->orderByDesc('work_date')->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'date' => Carbon::parse($r->work_date)->format('d M Y'),
                    'window' => Carbon::parse($r->requested_check_in)->format('H:i').' → '.Carbon::parse($r->requested_check_out)->format('H:i'),
                    'reason' => $r->reason,
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
        if ($request->status !== 'pending') {
            return;
        }

        $attendance = app(AttendanceService::class)->approveRegularisation($request, Auth::id());
        AuditLog::record($attendance, 'regularised', $attendance->toArray(), null);
        $request->employee->user?->notify(new RegularisationReviewedNotification($request));

        \Flux::toast('Regularisation approved — hours & attendance updated.');
        if ($this->drawerEmployeeId) {
            $this->openEmployeeDrawer($this->drawerEmployeeId); // refresh drawer data
        }
    }

    public function openReviewModal(int $id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->activeRequest = AttendanceRegularisation::with('employee.user', 'attendance', 'reviewer')->findOrFail($id);
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

        AuditLog::record($attendance, 'regularised', $attendance->toArray(), null);

        $this->activeRequest->employee->user->notify(new RegularisationReviewedNotification($this->activeRequest));

        $this->showReviewModal = false;
        $this->activeRequest = null;
        \Flux::toast('Regularisation request approved.');
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

        $this->showReviewModal = false;
        $this->activeRequest = null;
        \Flux::toast('Regularisation request rejected.');
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

        $query = Attendance::query()->with('employee.user')->whereHas('employee.user');

        $this->applyFilters($query);

        $pendingRegularisations = AttendanceRegularisation::where('status', 'pending')
            ->with(['employee.user', 'attendance'])
            ->whereHas('employee.user')
            ->get();

        // KPI stats for today
        $today = Carbon::today();
        $totalActive = Employee::where('status', 'active')->count();
        $todayRecords = Attendance::where('date', $today)->get();
        $presentToday = $todayRecords->whereNotNull('check_in')->count();
        $lateToday = $todayRecords->where('is_late', true)->count();
        $onTimeToday = $presentToday - $lateToday;
        $absentToday = max(0, $totalActive - $presentToday);

        $stats = [
            'total' => $totalActive,
            'present' => $presentToday,
            'absent' => $absentToday,
            'on_time' => $onTimeToday,
            'late' => $lateToday,
            'wfh' => $todayRecords->whereIn('work_mode', ['wfh', 'hybrid'])->count(),
            'present_pct' => $totalActive > 0 ? round(($presentToday / $totalActive) * 100, 1) : 0,
            'absent_pct' => $totalActive > 0 ? round(($absentToday / $totalActive) * 100, 1) : 0,
            'late_pct' => $presentToday > 0 ? round(($lateToday / $presentToday) * 100, 1) : 0,
        ];

        // Presence trend across the selected range (drives the overview chart).
        $trend = [];
        if ($this->dateFrom && $this->dateTo) {
            $byDay = Attendance::whereBetween('date', [$this->dateFrom, $this->dateTo])
                ->get(['date', 'is_late'])
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
            'allEmployees' => Employee::with('user')->whereHas('user')->orderBy('id')->get(),
            'stats' => $stats,
            'trend' => $trend,
            'weekLabel' => $weekLabel,
        ])->layout('layouts.app', ['title' => 'Employee Attendance']);
    }
}
