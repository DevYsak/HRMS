<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\HandlesClaimLock;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\HolidayWorkRequest;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\WfhRequest;
use App\Notifications\OtRequestNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Notifications\WfhRequestNotification;
use App\Services\AttendanceService;
use App\Services\HolidayWorkService;
use App\Services\LeaveService;
use App\Services\OvertimeService;
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
        $this->selected = $this->pendingQuery()->pluck('id')->map(fn ($id) => (int) $id)->all();
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
        $request = AttendanceRegularisation::with('employee.user', 'claimer')->findOrFail($id);
        if ($request->status !== 'pending') {
            throw new \DomainException('Already reviewed.');
        }
        $this->assertNotClaimedByOther($request);

        if ($decision === 'approved') {
            $attendance = app(AttendanceService::class)->approveRegularisation($request, Auth::id(), $comment);
            if ($attendance) {                    // null = advanced a stage, not yet final
                AuditLog::record($attendance, 'regularised', $attendance->toArray(), null);
            }
        } else {
            app(AttendanceService::class)->rejectRegularisation($request, Auth::id(), $comment ?? '');
        }

        $request->employee->user?->notify(new RegularisationReviewedNotification($request->fresh()));
    }

    protected function decideLeave(int $id, string $decision, ?string $comment = null): void
    {
        $request = LeaveRequest::with('employee.user', 'claimer')->findOrFail($id);
        if ($request->status !== 'pending') {
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
        $request = WfhRequest::with('employee.user')->findOrFail($id);
        if (! $request->isPending()) {
            throw new \DomainException('Already reviewed.');
        }

        $decision === 'approved'
            ? app(WfhService::class)->approve($request, Auth::id(), $comment)
            : app(WfhService::class)->reject($request, Auth::id(), $comment ?? '');

        $request->employee->user?->notify(new WfhRequestNotification($request->fresh()));
    }

    protected function decideOvertime(int $id, string $decision, ?string $comment = null): void
    {
        $request = OtRequest::with(['employee.user', 'attendance', 'claimer'])->findOrFail($id);
        if (! $request->isPending()) {
            throw new \DomainException('Already reviewed.');
        }
        $this->assertNotClaimedByOther($request);

        $decision === 'approved'
            ? app(OvertimeService::class)->approve($request, Auth::id(), $comment)
            : app(OvertimeService::class)->reject($request, Auth::id(), $comment ?? '');

        $request->employee->user?->notify(new OtRequestNotification($request->fresh()));
    }

    protected function decideHolidayWork(int $id, string $decision, ?string $comment = null): void
    {
        $request = HolidayWorkRequest::with('employee.user')->findOrFail($id);
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

    protected function pendingQuery()
    {
        $q = match ($this->tab) {
            'leave' => LeaveRequest::query()->with(['employee.user', 'leaveType']),
            'wfh' => WfhRequest::query()->with('employee.user'),
            'overtime' => OtRequest::query()->with('employee.user'),
            'holiday' => HolidayWorkRequest::query()->with(['employee.user', 'holiday']),
            default => AttendanceRegularisation::query()->with('employee.user'),
        };

        // Status filter: pending (default), a decided state, or the full history.
        match ($this->statusFilter) {
            'approved' => $q->where('status', 'approved'),
            'rejected' => $q->where('status', 'rejected'),
            'all' => $q,
            default => $q->where('status', 'pending'),
        };

        if ($this->search !== '') {
            $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', '%'.$this->search.'%'));
        }

        return $q->latest('created_at');
    }

    /** Normalise a row for the list — carries its status so history rows badge. */
    protected function normalise($item): array
    {
        $base = [
            'id' => $item->id,
            'status' => $item->status,
            'employee' => $item->employee?->user?->name ?? '—',
            'reason' => $item->reason,
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
                    ? 'Correct to '.Carbon::parse($item->requested_check_in)->format('H:i').' → '.Carbon::parse($item->requested_check_out)->format('H:i')
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

        return $map(AttendanceRegularisation::with(['employee.user', 'reviewer'])->whereIn('status', ['approved', 'rejected'])->whereNotNull('reviewed_at')->latest('reviewed_at')->limit(8)->get(), 'Regularisation', 'reviewed_at')
            // LeaveRequest has no reviewed_at column — updated_at marks the decision.
            ->concat($map(LeaveRequest::with(['employee.user', 'reviewer'])->whereIn('status', ['approved', 'rejected'])->latest('updated_at')->limit(8)->get(), 'Leave', 'updated_at'))
            ->concat($map(WfhRequest::with(['employee.user', 'reviewer'])->whereIn('status', ['approved', 'rejected'])->whereNotNull('reviewed_at')->latest('reviewed_at')->limit(8)->get(), 'WFH', 'reviewed_at'))
            ->concat($map(OtRequest::with(['employee.user', 'reviewer'])->whereIn('status', ['approved', 'rejected'])->whereNotNull('reviewed_at')->latest('reviewed_at')->limit(8)->get(), 'Overtime', 'reviewed_at'))
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
            ->concat($map(AttendanceRegularisation::with('employee.user')->where('status', 'pending')->latest()->limit(6)->get(), 'Regularization', 'regularisation', fn ($r) => Carbon::parse($r->work_date)->format('d M Y')))
            ->concat($map(LeaveRequest::with(['employee.user', 'leaveType'])->where('status', 'pending')->latest()->limit(6)->get(), 'Leave', 'leave', fn ($r) => ($r->leaveType?->name ?? 'Leave').' · '.$r->days.'d'))
            ->concat($map(WfhRequest::with('employee.user')->where('status', 'pending')->latest()->limit(6)->get(), 'WFH', 'wfh', fn ($r) => Carbon::parse($r->start_date)->format('d M').' – '.Carbon::parse($r->end_date)->format('d M')))
            ->concat($map(OtRequest::with('employee.user')->where('status', 'pending')->latest()->limit(6)->get(), 'Overtime', 'overtime', fn ($r) => 'OT '.$r->requested_hours.'h'))
            ->concat($map(HolidayWorkRequest::with(['employee.user', 'holiday'])->where('status', 'pending')->latest()->limit(6)->get(), 'Holiday Work', 'holiday', fn ($r) => 'Work on '.($r->holiday?->name ?? 'holiday')))
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

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $counts = [
            'regularisation' => AttendanceRegularisation::where('status', 'pending')->count(),
            'leave' => LeaveRequest::where('status', 'pending')->count(),
            'wfh' => WfhRequest::where('status', 'pending')->count(),
            'overtime' => OtRequest::where('status', 'pending')->count(),
            'holiday' => HolidayWorkRequest::where('status', 'pending')->count(),
        ];
        $decided = [
            'approved' => AttendanceRegularisation::where('status', 'approved')->count()
                + LeaveRequest::where('status', 'approved')->count()
                + WfhRequest::where('status', 'approved')->count()
                + OtRequest::where('status', 'approved')->count(),
            'rejected' => AttendanceRegularisation::where('status', 'rejected')->count()
                + LeaveRequest::where('status', 'rejected')->count()
                + WfhRequest::where('status', 'rejected')->count()
                + OtRequest::where('status', 'rejected')->count(),
        ];

        return view('livewire.attendance.command-center', [
            'counts' => $counts,
            'decided' => $decided,
            'items' => $this->pendingQuery()->limit(50)->get()->map(fn ($i) => $this->normalise($i))->all(),
            'feed' => $this->activityFeed(),
            'incoming' => $this->recentIncoming(),
        ])->layout('layouts.app', ['title' => 'Attendance Command Center']);
    }
}
