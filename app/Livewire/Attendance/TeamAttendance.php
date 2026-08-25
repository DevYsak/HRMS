<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class TeamAttendance extends Component
{
    use WithPagination;

    public $showReviewModal = false;

    public $activeRequest = null;

    public $reviewComment = '';

    /** Period the analytics strip (KPIs, scores, ranking) aggregates over. */
    #[Url]
    public string $period = 'this_month';

    public function updatingPeriod(): void
    {
        $this->resetPage();
    }

    /**
     * The [start, end] the analytics period resolves to (end capped at today).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodRange(): array
    {
        $today = Carbon::today();
        [$start, $end] = match ($this->period) {
            'this_week' => [$today->copy()->startOfWeek(Carbon::SUNDAY), $today->copy()->endOfWeek(Carbon::SATURDAY)],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [$today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter()],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        };

        return [$start->startOfDay(), $end->endOfDay()->min($today->copy()->endOfDay())];
    }

    public function openReviewModal(int $id)
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $this->activeRequest = AttendanceRegularisation::with('employee.user', 'attendance')->findOrFail($id);
        $this->reviewComment = '';
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

        $this->showReviewModal = false;
        $this->activeRequest = null;
    }

    public function rejectRegularisation()
    {
        if (! $this->activeRequest) {
            return;
        }

        $this->validate(['reviewComment' => 'required|string|min:5']);
        app(AttendanceService::class)->rejectRegularisation($this->activeRequest, Auth::id(), $this->reviewComment);

        $this->activeRequest->employee->user->notify(new RegularisationReviewedNotification($this->activeRequest));

        $this->showReviewModal = false;
        $this->activeRequest = null;
        \Flux::toast('Regularisation request rejected.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $manager = Auth::user()->employee;
        $teamMembers = $manager ? $manager->subordinates()->with('user')->get() : collect();
        $teamIds = $teamMembers->pluck('id')->toArray();

        $currentlyIn = Attendance::whereIn('employee_id', $teamIds)
            ->where('date', Carbon::today())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->with('employee.user')
            ->get();

        // ── Live board: today's status for every team member ──
        $today = Carbon::today()->toDateString();
        $attToday = Attendance::whereIn('employee_id', $teamIds)
            ->where('date', $today)
            ->with('activeBreak')
            ->get()->keyBy('employee_id');
        $onLeave = LeaveRequest::whereIn('employee_id', $teamIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->pluck('employee_id')->flip();

        $board = $teamMembers->map(function ($m) use ($attToday, $onLeave) {
            $att = $attToday->get($m->id);
            $status = match (true) {
                isset($onLeave[$m->id]) => 'on_leave',
                $att && $att->check_out => 'completed',
                $att && $att->activeBreak => 'on_break',
                (bool) $att => $att->is_late ? 'late' : 'working',
                default => 'absent',
            };

            return [
                'name' => $m->user?->name ?? '—',
                'photo' => $m->photo,
                'mode' => $att?->work_mode,
                'status' => $status,
                'since' => $att?->check_in?->format('h:i A'),
            ];
        })->sortBy(fn ($r) => ['working' => 0, 'late' => 1, 'on_break' => 2, 'completed' => 3, 'on_leave' => 4, 'absent' => 5][$r['status']] ?? 9)->values();

        $active = $board->whereIn('status', ['working', 'late', 'on_break', 'completed']);
        $boardStats = [
            'working' => $board->whereIn('status', ['working', 'late', 'on_break'])->count(),
            'office' => $active->where('mode', 'office')->count(),
            'wfh' => $active->whereIn('mode', ['wfh', 'hybrid'])->count(),
            'late' => $board->where('status', 'late')->count(),
            'absent' => $board->where('status', 'absent')->count(),
            'on_leave' => $board->where('status', 'on_leave')->count(),
        ];

        $recentLogs = Attendance::whereIn('employee_id', $teamIds)
            ->with(['employee.user', 'regularisation'])
            ->latest('date')
            ->paginate(10);

        $pendingRegularisations = AttendanceRegularisation::whereIn('employee_id', $teamIds)
            ->where('status', 'pending')
            ->with(['employee.user', 'attendance'])
            ->get();

        // ── Period analytics strip: recomputes from the filter (Rule 12) ──────
        [$rangeStart, $rangeEnd] = $this->periodRange();
        $rangeAtt = Attendance::whereIn('employee_id', $teamIds)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['employee_id', 'date', 'check_in', 'check_out', 'is_late', 'break_minutes', 'total_hours']);
        $present = $rangeAtt->whereNotNull('check_in');
        $workedMin = $rangeAtt->sum(fn ($a) => $a->check_in && $a->check_out
            ? max(0, $a->check_in->diffInMinutes($a->check_out) - (int) ($a->break_minutes ?? 0)) : 0);

        // Engine attendance score for the team over the range + per-member scores.
        $scoreRows = AttendanceDailyScore::whereIn('employee_id', $teamIds)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['employee_id', 'score']);
        $scoreByMember = $scoreRows->groupBy('employee_id')->map(fn ($g) => round($g->avg('score'), 1));

        $periodStats = [
            'present' => $present->count(),
            'late' => $present->where('is_late', true)->count(),
            'overtime_hours' => round($rangeAtt->sum(fn ($a) => max(0, (float) ($a->total_hours ?? 0) - 9)), 1),
            'worked_hours' => round($workedMin / 60),
            'avg_score' => $scoreRows->isNotEmpty() ? (int) round($scoreRows->avg('score')) : 0,
        ];

        // Team score ranking (best attendance score in the period).
        $scoreRanking = $teamMembers->map(fn ($m) => [
            'name' => $m->user?->name ?? '—',
            'score' => $scoreByMember[$m->id] ?? null,
        ])->filter(fn ($r) => $r['score'] !== null)->sortByDesc('score')->take(5)->values()->all();

        return view('livewire.attendance.team-attendance', [
            'currentlyIn' => $currentlyIn,
            'recentLogs' => $recentLogs,
            'pendingRegularisations' => $pendingRegularisations,
            'board' => $board,
            'boardStats' => $boardStats,
            'periodStats' => $periodStats,
            'scoreRanking' => $scoreRanking,
            'periodLabel' => $rangeStart->format('d M').' – '.$rangeEnd->format('d M Y'),
        ])->layout('layouts.app', ['title' => 'Team Attendance']);
    }
}
