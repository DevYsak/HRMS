<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\HandlesClaimLock;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\HolidayWorkRequest;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\WfhRequest;
use App\Notifications\OtRequestNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Notifications\WfhRequestNotification;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\TodayStatusService;
use App\Services\AttendanceService;
use App\Services\HolidayWorkService;
use App\Services\LeaveService;
use App\Services\OvertimeService;
use App\Services\Security\PeopleScope;
use App\Services\WfhService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Livewire\Component;

/**
 * Attendance Command Center — every pending approval (regularisation, leave,
 * WFH, overtime) in one hub with quick + bulk actions and an activity feed.
 * Each decision routes through the owning module's service so recalculation,
 * balances, records and notifications behave exactly as in that module.
 */
class CommandCenter extends Component
{
    use HandlesClaimLock;

    public string $tab = 'regularisation';

    public string $search = '';

    /** History filter: pending | approved | rejected | all. */
    public string $statusFilter = 'pending';

    /** @var array<int, int> Selected pending ids on the active tab. */
    public array $selected = [];

    public string $rejectComment = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        // Open on the first category this person can decide (a read-only
        // category only when there is nothing they decide).
        $queue = app(ApprovalQueueService::class);
        $tabs = $this->availableTabs();
        $decidable = array_values(array_filter($tabs, fn (string $type) => $queue->handles(Auth::user(), $type)));
        $preferred = $decidable !== [] ? $decidable : $tabs;
        if (! in_array($this->tab, $preferred, true) && $preferred !== []) {
            $this->tab = $preferred[0];
        }
    }

    /**
     * Categories this person may see: every type they decide, plus
     * regularisations read-only for approvers who hold approve_regularisation
     * (they are decided by HR).
     *
     * @return list<string>
     */
    public function availableTabs(): array
    {
        return array_values(array_filter(array_keys(ApprovalQueueService::TYPES), fn (string $type) => $this->tabPermission($type) !== null));
    }

    /** The permission whose reach a category is listed under (null = not visible). */
    protected function tabPermission(string $type): ?string
    {
        $user = Auth::user();
        $queue = app(ApprovalQueueService::class);

        if ($queue->handles($user, $type)) {
            return $queue->permissionFor($type);
        }

        return $type === 'regularisation' && $user->hasPermission('approve_regularisation') ? 'approve_regularisation' : null;
    }

    public function selectDecidable(): void
    {
        $queue = app(ApprovalQueueService::class);
        $this->selected = $this->pendingQuery()->with('employee')->get()
            ->filter(fn ($r) => $queue->canDecide(Auth::user(), $r))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function updatedTab(): void
    {
        $this->selected = [];
    }

    public function updatedSearch(): void
    {
        $this->selected = [];
    }

    public function updatedStatusFilter(): void
    {
        $this->selected = [];
    }

    public function selectAll(): void
    {
        // Only rows this person can decide — never a selection that bulk-fails.
        $this->selectDecidable();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    // ── Decisions (each routed through the owning module's service) ─────────

    public function approveOne(string $type, int $id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        try {
            $this->approveByType($type, $id);
            \Flux::toast(ucfirst($type).' request approved.');
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }

        $this->selected = array_values(array_diff($this->selected, [$id]));
    }

    public function rejectOne(string $type, int $id): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        if (strlen(trim($this->rejectComment)) < 5) {
            \Flux::toast('Add a rejection comment (min 5 characters) first.', variant: 'warning');

            return;
        }

        try {
            $this->rejectByType($type, $id, trim($this->rejectComment));
            \Flux::toast(ucfirst($type).' request rejected.', variant: 'warning');
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }

        $this->selected = array_values(array_diff($this->selected, [$id]));
    }

    public function bulkApprove(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $done = 0;
        $failed = 0;
        foreach ($this->selected as $id) {
            try {
                $this->approveByType($this->tab, (int) $id);
                $done++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->selected = [];
        \Flux::toast("Bulk approve: {$done} approved".($failed ? ", {$failed} failed" : '.'), variant: $failed ? 'warning' : 'success');
    }

    public function bulkReject(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        if (strlen(trim($this->rejectComment)) < 5) {
            \Flux::toast('Add a rejection comment (min 5 characters) — it is applied to every selected request.', variant: 'warning');

            return;
        }

        $done = 0;
        $failed = 0;
        foreach ($this->selected as $id) {
            try {
                $this->rejectByType($this->tab, (int) $id, trim($this->rejectComment));
                $done++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->selected = [];
        \Flux::toast("Bulk reject: {$done} rejected".($failed ? ", {$failed} failed" : '.'), variant: $failed ? 'warning' : 'success');
    }

    protected function approveByType(string $type, int $id): void
    {
        match ($type) {
            'regularisation' => $this->decideRegularisation($id, 'approved'),
            'leave' => $this->decideLeave($id, 'approved'),
            'wfh' => $this->decideWfh($id, 'approved'),
            'overtime' => $this->decideOvertime($id, 'approved'),
            'holiday' => $this->decideHolidayWork($id, 'approved'),
            default => null,
        };
    }

    protected function rejectByType(string $type, int $id, string $comment): void
    {
        match ($type) {
            'regularisation' => $this->decideRegularisation($id, 'rejected', $comment),
            'leave' => $this->decideLeave($id, 'rejected', $comment),
            'wfh' => $this->decideWfh($id, 'rejected', $comment),
            'overtime' => $this->decideOvertime($id, 'rejected', $comment),
            'holiday' => $this->decideHolidayWork($id, 'rejected', $comment),
            default => null,
        };
    }

    protected function decideRegularisation(int $id, string $decision, ?string $comment = null): void
    {
        $request = $this->inReach(AttendanceRegularisation::query())->with('employee.user', 'claimer')->findOrFail($id);
        if ($request->status !== 'pending') {
            throw new \DomainException('Already reviewed.');
        }
        $this->assertNotClaimedByOther($request);

        if ($decision === 'approved') {
            // AttendanceService records the decision, with the day before and after.
            app(AttendanceService::class)->approveRegularisation($request, Auth::id(), $comment);
        } else {
            app(AttendanceService::class)->rejectRegularisation($request, Auth::id(), $comment ?? '');
        }

        $request->employee->user?->notify(new RegularisationReviewedNotification($request->fresh()));
    }

    protected function decideLeave(int $id, string $decision, ?string $comment = null): void
    {
        $request = $this->inReach(LeaveRequest::query())->with('employee.user', 'claimer')->findOrFail($id);
        if (! in_array($request->status, ['pending', 'pending_hr'], true)) {
            throw new \DomainException('Already reviewed.');
        }
        $this->assertNotClaimedByOther($request);

        $form = [
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'reason' => $request->reason,
            'is_half_day' => (bool) $request->is_half_day,
        ];

        // LeaveService::reviewRequest handles balances + employee notification.
        app(LeaveService::class)->reviewRequest($request, $form, $decision, Auth::id(), $comment);
    }

    protected function decideWfh(int $id, string $decision, ?string $comment = null): void
    {
        $request = $this->inReach(WfhRequest::query())->with('employee.user')->findOrFail($id);
        if (! $request->isPending()) {
            throw new \DomainException('Already reviewed.');
        }

        $decision === 'approved'
            ? app(WfhService::class)->approve($request, Auth::id(), $comment)
            : app(WfhService::class)->reject($request, Auth::id(), $comment ?? '');

        $request->employee->user?->notify((new WfhRequestNotification($request->fresh()))->forRole('employee'));
    }

    protected function decideOvertime(int $id, string $decision, ?string $comment = null): void
    {
        // The page admits leave approvers; OT is its own permission.
        abort_unless(Auth::user()->canApproveOt(), 403);

        $request = $this->inReach(OtRequest::query())->with(['employee.user', 'attendance', 'claimer'])->findOrFail($id);
        if (! $request->isPending()) {
            throw new \DomainException('Already reviewed.');
        }
        $this->assertNotClaimedByOther($request);

        $decision === 'approved'
            ? app(OvertimeService::class)->approve($request, Auth::id(), $comment)
            : app(OvertimeService::class)->reject($request, Auth::id(), $comment ?? '');

        $request->employee->user?->notify((new OtRequestNotification($request->fresh()))->forRole('employee'));
    }

    protected function decideHolidayWork(int $id, string $decision, ?string $comment = null): void
    {
        $request = $this->inReach(HolidayWorkRequest::query())->with('employee.user')->findOrFail($id);
        if (! $request->isPending()) {
            throw new \DomainException('Already reviewed.');
        }

        // HolidayWorkService materialises the holiday-worked attendance + pay
        // and notifies the employee.
        $decision === 'approved'
            ? app(HolidayWorkService::class)->approve($request, Auth::id(), $comment)
            : app(HolidayWorkService::class)->reject($request, Auth::id(), $comment ?? '');
    }

    // ── Data ─────────────────────────────────────────────────────────────────

    /**
     * Restrict a request query to the reach of the permission that decides
     * its type (approve_leave, approve_overtime, approve_wfh,
     * hr_approve_regularisation …) — the same reach the service checks on
     * Approve / Reject. A category the user cannot see returns nothing.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function inReach($query)
    {
        $type = collect(ApprovalQueueService::TYPES)->search(fn ($config) => $config['model'] === get_class($query->getModel()));
        $permission = $type ? $this->tabPermission($type) : null;

        return $permission
            ? app(PeopleScope::class)->constrain($query, Auth::user(), $permission)
            : $query->whereRaw('1 = 0');
    }

    protected function pendingQuery()
    {
        $q = match ($this->tab) {
            'leave' => $this->inReach(LeaveRequest::query())->with(['employee.user', 'leaveType']),
            'wfh' => $this->inReach(WfhRequest::query())->with('employee.user'),
            'overtime' => $this->inReach(OtRequest::query())->with('employee.user'),
            'holiday' => $this->inReach(HolidayWorkRequest::query())->with(['employee.user', 'holiday']),
            default => $this->inReach(AttendanceRegularisation::query())->with('employee.user'),
        };

        // Status filter: pending (default), a decided state, or the full history.
        match ($this->statusFilter) {
            'approved' => $q->where('status', 'approved'),
            'rejected' => $q->where('status', 'rejected'),
            'all' => $q,
            default => $q->whereIn('status', ApprovalQueueService::TYPES[$this->tab]['statuses'] ?? ['pending']),
        };

        if ($this->search !== '') {
            $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', '%'.$this->search.'%'));
        }

        return $q->latest('created_at');
    }

    /** Normalise a row for the list — carries its status so history rows badge. */
    protected function normalise($item): array
    {
        $queue = app(ApprovalQueueService::class);
        $path = $queue->path($item);

        $base = [
            'id' => $item->id,
            'status' => $item->status,
            'employee' => $item->employee?->user?->name ?? '—',
            'reason' => $item->reason,
            // Ownership: whose request, where it sits, who holds it, what happens next.
            'department' => $item->employee?->department?->name,
            'manager' => $item->employee?->manager?->name,
            'submitted' => $item->created_at,
            'approver' => $path['current'],
            'next' => $path['next'],
            'escalated' => $path['escalated'],
            'can_decide' => $queue->canDecide(Auth::user(), $item),
        ];

        return array_merge($base, match ($this->tab) {
            'leave' => [
                'when' => Carbon::parse($item->start_date)->format('d M').' – '.Carbon::parse($item->end_date)->format('d M Y'),
                'detail' => ($item->leaveType?->name ?? 'Leave').' · '.$item->days.' day(s)',
            ],
            'wfh' => [
                'when' => Carbon::parse($item->start_date)->format('d M').' – '.Carbon::parse($item->end_date)->format('d M Y'),
                'detail' => 'Work From Home',
            ],
            'overtime' => [
                'when' => Carbon::parse($item->work_date)->format('d M Y'),
                'detail' => 'OT '.$item->requested_hours.'h · '.$item->start_time.'–'.$item->end_time,
            ],
            'holiday' => [
                'when' => Carbon::parse($item->work_date)->format('d M Y'),
                'detail' => 'Work on '.($item->holiday?->name ?? 'holiday').' · '.$item->expected_hours.'h · '.$item->payTypeLabel(),
            ],
            default => [
                'when' => Carbon::parse($item->work_date)->format('d M Y'),
                'detail' => $item->requested_check_in
                    ? 'Correct to '.$item->requestedWindowLabel()
                    : 'Half-day ('.$item->half_day_period.')',
            ],
        });
    }

    /** Recent decisions across all four request types, newest first. */
    protected function activityFeed(): array
    {
        $map = fn ($rows, string $type, string $when) => $rows->map(fn ($r) => [
            'type' => $type,
            'employee' => $r->employee?->user?->name ?? '—',
            'status' => $r->status,
            'reviewer' => $r->reviewer?->name,
            'at' => $r->{$when},
        ]);

        return $map($this->inReach(AttendanceRegularisation::query())->with(['employee.user', 'reviewer'])->whereIn('status', ['approved', 'rejected'])->whereNotNull('reviewed_at')->latest('reviewed_at')->limit(8)->get(), 'Regularisation', 'reviewed_at')
            // LeaveRequest has no reviewed_at column — updated_at marks the decision.
            ->concat($map($this->inReach(LeaveRequest::query())->with(['employee.user', 'reviewer'])->whereIn('status', ['approved', 'rejected'])->latest('updated_at')->limit(8)->get(), 'Leave', 'updated_at'))
            ->concat($map($this->inReach(WfhRequest::query())->with(['employee.user', 'reviewer'])->whereIn('status', ['approved', 'rejected'])->whereNotNull('reviewed_at')->latest('reviewed_at')->limit(8)->get(), 'WFH', 'reviewed_at'))
            ->concat($map($this->inReach(OtRequest::query())->with(['employee.user', 'reviewer'])->whereIn('status', ['approved', 'rejected'])->whereNotNull('reviewed_at')->latest('reviewed_at')->limit(8)->get(), 'Overtime', 'reviewed_at'))
            ->sortByDesc('at')
            ->take(15)
            ->values()
            ->all();
    }

    /** Newest still-pending requests across all categories, newest first. */
    protected function recentIncoming(): array
    {
        $map = fn ($rows, string $type, string $tab, callable $detail) => $rows->map(fn ($r) => [
            'type' => $type,
            'tab' => $tab,
            'employee' => $r->employee?->user?->name ?? '—',
            'detail' => $detail($r),
            'at' => $r->created_at,
        ]);

        return collect()
            ->concat($map($this->inReach(AttendanceRegularisation::query())->with('employee.user')->where('status', 'pending')->latest()->limit(6)->get(), 'Regularization', 'regularisation', fn ($r) => Carbon::parse($r->work_date)->format('d M Y')))
            ->concat($map($this->inReach(LeaveRequest::query())->with(['employee.user', 'leaveType'])->where('status', 'pending')->latest()->limit(6)->get(), 'Leave', 'leave', fn ($r) => ($r->leaveType?->name ?? 'Leave').' · '.$r->days.'d'))
            ->concat($map($this->inReach(WfhRequest::query())->with('employee.user')->where('status', 'pending')->latest()->limit(6)->get(), 'WFH', 'wfh', fn ($r) => Carbon::parse($r->start_date)->format('d M').' – '.Carbon::parse($r->end_date)->format('d M')))
            ->concat($map($this->inReach(OtRequest::query())->with('employee.user')->where('status', 'pending')->latest()->limit(6)->get(), 'Overtime', 'overtime', fn ($r) => 'OT '.$r->requested_hours.'h'))
            ->concat($map($this->inReach(HolidayWorkRequest::query())->with(['employee.user', 'holiday'])->where('status', 'pending')->latest()->limit(6)->get(), 'Holiday Work', 'holiday', fn ($r) => 'Work on '.($r->holiday?->name ?? 'holiday')))
            ->sortByDesc('at')
            ->take(10)
            ->values()
            ->all();
    }

    public function exportPending()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $rows = $this->pendingQuery()->get()->map(fn ($i) => $this->normalise($i));
        $csv = "Employee,When,Detail,Reason\n";
        foreach ($rows as $r) {
            $csv .= '"'.$r['employee'].'","'.$r['when'].'","'.$r['detail'].'","'.str_replace('"', "'", (string) $r['reason'])."\"\n";
        }

        return Response::streamDownload(fn () => print ($csv), "pending-{$this->tab}-".now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Live attendance-health context for approvers — today's presence plus the
     * month-to-date engine score, at-risk count and score-band distribution
     * (Rule 11). Gives decisions context without leaving the hub.
     *
     * @return array<string, mixed>
     */
    protected function attendanceHealth(): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        // Same population and same per-person status as Team View, the HR
        // dashboard and Live Attendance: the view_attendance reach, read
        // through AttendanceStatusResolver — a weekly off, holiday or leave is
        // never "absent".
        $ids = app(PeopleScope::class)->workingEmployeeIds(Auth::user(), 'view_attendance');
        $today_ = app(TodayStatusService::class)->summary($ids);

        $band = app(AttendanceScoreEngine::class)->companyHealth($ids, $monthStart, $today);

        return [
            'active' => count($ids),
            'expected' => $today_['expected'],
            'present_today' => $today_['present'],
            'late_today' => $today_['late'],
            'absent_today' => $today_['absent'],
            'no_checkin' => $today_['no_checkin'],
            'on_leave' => $today_['on_leave'],
            'weekly_off' => $today_['weekly_off'],
            'holiday' => $today_['holiday'],
            'missing_checkout' => $today_['missing_checkout'],
            'day_off' => $today_['day_off'],
            'mtd_score' => $band['avg_score'],
            'at_risk' => $band['at_risk'],
            'bands' => $band['bands'],
        ];
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        // One definition of "pending" per category (ApprovalQueueService), over
        // that category's own reach; each card names the scope it counts.
        $people = app(PeopleScope::class);
        $queue = app(ApprovalQueueService::class);
        $counts = [];
        $categoryScope = [];
        $viewOnly = [];
        foreach ($this->availableTabs() as $type) {
            $counts[$type] = $this->inReach(ApprovalQueueService::TYPES[$type]['model']::query())
                ->whereIn('status', ApprovalQueueService::TYPES[$type]['statuses'])->count();
            $categoryScope[$type] = $people->summary(Auth::user(), $this->tabPermission($type))['label'];
            $viewOnly[$type] = ! $queue->handles(Auth::user(), $type);
        }
        $decided = [
            'approved' => $this->inReach(AttendanceRegularisation::query())->where('status', 'approved')->count()
                + $this->inReach(LeaveRequest::query())->where('status', 'approved')->count()
                + $this->inReach(WfhRequest::query())->where('status', 'approved')->count()
                + $this->inReach(OtRequest::query())->where('status', 'approved')->count(),
            'rejected' => $this->inReach(AttendanceRegularisation::query())->where('status', 'rejected')->count()
                + $this->inReach(LeaveRequest::query())->where('status', 'rejected')->count()
                + $this->inReach(WfhRequest::query())->where('status', 'rejected')->count()
                + $this->inReach(OtRequest::query())->where('status', 'rejected')->count(),
        ];

        return view('livewire.attendance.command-center', [
            'counts' => $counts,
            'categoryScope' => $categoryScope,
            'viewOnly' => $viewOnly,
            'scope' => $people->summary(Auth::user(), 'view_attendance'),
            'decided' => $decided,
            'items' => $this->pendingQuery()->limit(50)->get()->map(fn ($i) => $this->normalise($i))->all(),
            'feed' => $this->activityFeed(),
            'incoming' => $this->recentIncoming(),
            'health' => $this->attendanceHealth(),
        ])->layout('layouts.app', ['title' => 'Attendance Command Center']);
    }
}
