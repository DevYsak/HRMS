<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Livewire\Concerns\HandlesClaimLock;
use App\Models\AttendanceRegularisation;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\User;
use App\Notifications\OtRequestNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\Approvals\ApprovalGuard;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Services\OvertimeService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Approval Command Center — actionable, unified pending-request queue.
 *
 * Reuses each module's existing service for approve/reject so business logic,
 * staging and notifications are unchanged. Only surfaces requests the current
 * user is permitted to action.
 */
class ApprovalCenter extends Component
{
    use HandlesClaimLock;

    /** Block the action when another reviewer is actively handling the request. */
    protected function guardClaim(Model $request): bool
    {
        if ($this->claimHeldByOther($request)) {
            \Flux::toast('Being handled by '.($request->claimer?->name ?? 'another reviewer').' — no action needed.', variant: 'warning');

            return false;
        }

        return true;
    }

    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function approve(string $type, int $id): void
    {
        $this->handle($type, $id, 'approved');
    }

    public function reject(string $type, int $id): void
    {
        $this->handle($type, $id, 'rejected');
    }

    protected function handle(string $type, int $id, string $action): void
    {
        $user = Auth::user();
        $comment = 'Reviewed from the Approval Center.';

        try {
            switch ($type) {
                case 'leave':
                    abort_unless($user->canApproveLeave(), 403);
                    $req = LeaveRequest::with('claimer')->findOrFail($id);
                    app(ApprovalGuard::class)->assertCanDecide($user, $req->employee);
                    if (! $this->guardClaim($req)) {
                        return;
                    }
                    $form = [
                        'leave_type_id' => $req->leave_type_id,
                        'start_date' => $req->start_date->format('Y-m-d'),
                        'end_date' => $req->end_date->format('Y-m-d'),
                        'reason' => $req->reason,
                        'is_half_day' => (bool) $req->is_half_day,
                    ];
                    app(LeaveService::class)->reviewRequest($req, $form, $action, $user->id, $action === 'rejected' ? $comment : null);
                    break;

                case 'ot':
                    abort_unless($user->canApproveOt(), 403);
                    $req = OtRequest::with('claimer')->findOrFail($id);
                    app(ApprovalGuard::class)->assertCanDecide($user, $req->employee);
                    if (! $this->guardClaim($req)) {
                        return;
                    }
                    if ($action === 'approved') {
                        app(OvertimeService::class)->approve($req, $user->id);
                    } else {
                        app(OvertimeService::class)->reject($req, $user->id, $comment);
                    }
                    $req->employee?->user?->notify((new OtRequestNotification($req->fresh()))->forRole('employee'));
                    break;

                case 'regularisation':
                    abort_unless($user->canApproveLeave(), 403);
                    $req = AttendanceRegularisation::with('claimer')->findOrFail($id);
                    app(ApprovalGuard::class)->assertCanDecide($user, $req->employee);
                    if (! $this->guardClaim($req)) {
                        return;
                    }
                    if ($action === 'approved') {
                        app(AttendanceService::class)->approveRegularisation($req, $user->id);
                    } else {
                        app(AttendanceService::class)->rejectRegularisation($req, $user->id, $comment);
                    }
                    $req->employee?->user?->notify(new RegularisationReviewedNotification($req->fresh()));
                    break;

                case 'encashment':
                    $enc = LeaveEncashment::with('claimer')->findOrFail($id);
                    // Each stage is decided by whoever owns it: Director / HR
                    // Admin first, Finance once it has reached Finance.
                    $financeStage = $enc->status === 'pending_finance';
                    abort_unless($financeStage ? $user->canApproveFinance() : $this->decidesEncashmentFirstStage($user), 403);
                    app(ApprovalGuard::class)->assertNotSelf($user, $enc->employee);
                    if (! $this->guardClaim($enc)) {
                        return;
                    }
                    if ($action === 'approved') {
                        $financeStage
                            ? app(LeaveService::class)->financeApproveEncashment($user, $enc, (string) $comment)
                            : app(LeaveService::class)->approveEncashment($user, $enc, (string) $comment);
                    } else {
                        app(LeaveService::class)->rejectEncashment($user, $enc, (string) $comment);
                    }
                    break;

                default:
                    return;
            }

            \Flux::toast(ucfirst($type).' request '.$action.'.', variant: $action === 'approved' ? 'success' : 'danger');
        } catch (\Throwable $e) {
            \Flux::toast('Could not '.$action.' this request. '.$e->getMessage(), variant: 'danger');
        }
    }

    /**
     * Priority from age in days: 3+ = high, 1+ = medium, else normal.
     *
     * @return array{0:string,1:string} [label, color]
     */
    protected function priority(?CarbonInterface $submitted): array
    {
        $days = $submitted ? (int) abs($submitted->diffInDays(now())) : 0;

        return match (true) {
            $days >= 3 => ['High', 'rose'],
            $days >= 1 => ['Medium', 'amber'],
            default => ['Normal', 'zinc'],
        };
    }

    /** First-stage encashment deciders (Conexus policy): Super Admin, HR Admin, Director. */
    private function decidesEncashmentFirstStage(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isHrAdmin() || $user->role === UserRole::Director;
    }

    public function render()
    {
        $user = Auth::user();
        $canLeave = $user->canApproveLeave();
        $canOt = $user->canApproveOt();
        $canFin = $user->canApproveFinance();

        $rows = collect();

        // Fail closed: only requests inside the approver's reach, never their own.
        $scopeIds = $user->accessibleEmployeeIds();   // null = company-wide
        $ownEmployeeId = $user->employee?->id;
        $inReach = fn ($q) => $q
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('employee_id', $scopeIds))
            ->when($ownEmployeeId, fn ($q) => $q->where('employee_id', '!=', $ownEmployeeId));

        if ($canLeave) {
            foreach (LeaveRequest::whereIn('status', ['pending', 'pending_hr'])
                ->tap($inReach)
                ->with('employee.user', 'employee.department', 'leaveType')
                ->whereHas('employee.user')->latest()->get() as $r) {
                [$pl, $pc] = $this->priority($r->created_at);
                $rows->push([
                    'type' => 'leave', 'group' => 'leave', 'id' => $r->id,
                    'type_label' => ($r->leaveType?->name ?? 'Leave'),
                    'name' => $r->employee->user->name,
                    'dept' => $r->employee->department?->name ?? '—',
                    'date' => $r->start_date?->format('d M').' – '.$r->end_date?->format('d M'),
                    'submitted' => $r->created_at,
                    'priority' => $pl, 'priority_color' => $pc,
                    'view' => \Route::has('time-off.employees') ? route('time-off.employees') : '#',
                ]);
            }
        }

        if ($canOt) {
            foreach (OtRequest::where('status', 'pending')
                ->tap($inReach)
                ->with('employee.user', 'employee.department')
                ->whereHas('employee.user')->latest()->get() as $r) {
                [$pl, $pc] = $this->priority($r->created_at);
                $rows->push([
                    'type' => 'ot', 'group' => 'ot', 'id' => $r->id,
                    'type_label' => 'Overtime',
                    'name' => $r->employee->user->name,
                    'dept' => $r->employee->department?->name ?? '—',
                    'date' => $r->work_date ? Carbon::parse($r->work_date)->format('d M Y') : '—',
                    'submitted' => $r->created_at,
                    'priority' => $pl, 'priority_color' => $pc,
                    'view' => \Route::has('overtime.manage') ? route('overtime.manage') : '#',
                ]);
            }
        }

        if ($canLeave) {
            foreach (AttendanceRegularisation::where('status', 'pending')
                ->tap($inReach)
                ->with('employee.user', 'employee.department')
                ->whereHas('employee.user')->latest()->get() as $r) {
                [$pl, $pc] = $this->priority($r->created_at);
                $rows->push([
                    'type' => 'regularisation', 'group' => 'attendance', 'id' => $r->id,
                    'type_label' => 'Regularisation · '.$r->stageLabel(),
                    'name' => $r->employee->user->name,
                    'dept' => $r->employee->department?->name ?? '—',
                    'date' => $r->work_date?->format('d M Y') ?? '—',
                    'submitted' => $r->created_at,
                    'priority' => $pl, 'priority_color' => $pc,
                    'view' => \Route::has('attendance.employees') ? route('attendance.employees') : '#',
                ]);
            }
        }

        $encashmentStages = array_values(array_filter([
            $this->decidesEncashmentFirstStage($user) ? 'pending' : null,
            $canFin ? 'pending_finance' : null,
        ]));

        if ($encashmentStages !== []) {
            foreach (LeaveEncashment::whereIn('status', $encashmentStages)
                ->when($ownEmployeeId, fn ($q) => $q->where('employee_id', '!=', $ownEmployeeId))
                ->with('employee.user', 'employee.department')
                ->whereHas('employee.user')->latest()->get() as $r) {
                [$pl, $pc] = $this->priority($r->created_at);
                $rows->push([
                    'type' => 'encashment', 'group' => 'payroll', 'id' => $r->id,
                    'type_label' => 'Encashment',
                    'name' => $r->employee->user->name,
                    'dept' => $r->employee->department?->name ?? '—',
                    'date' => $r->created_at?->format('d M Y') ?? '—',
                    'submitted' => $r->created_at,
                    'priority' => $pl, 'priority_color' => $pc,
                    'view' => \Route::has('time-off.encashments') ? route('time-off.encashments') : '#',
                ]);
            }
        }

        $counts = [
            'all' => $rows->count(),
            'leave' => $rows->where('group', 'leave')->count(),
            'ot' => $rows->where('group', 'ot')->count(),
            'attendance' => $rows->where('group', 'attendance')->count(),
            'payroll' => $rows->where('group', 'payroll')->count(),
        ];

        $filtered = $this->filter === 'all'
            ? $rows->sortByDesc('submitted')->values()
            : $rows->where('group', $this->filter)->sortByDesc('submitted')->values();

        return view('livewire.approval-center', [
            'rows' => $filtered->take(6),
            'visibleTotal' => $filtered->count(),
            'counts' => $counts,
        ]);
    }
}
