<?php

namespace App\Livewire\TimeOff;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Leave\LeavePolicyEngine;
use App\Services\Leave\LeaveRequestAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * HR leave requests: every application in the viewer's reach, searchable
 * and filterable, each opening its detail page (LeaveRequestDetail). Read
 * only — decisions happen on the detail page, through LeaveService.
 */
class LeaveRequests extends Component
{
    use WithPagination;

    /** Tab => the statuses it covers. */
    public const TABS = [
        'all' => null,
        'pending' => ['pending', 'pending_hr', 'more_info_requested'],
        'approved' => ['approved'],
        'rejected' => ['rejected'],
        'cancelled' => ['cancelled'],
    ];

    #[Url(as: 'status')]
    public string $tab = 'all';

    /** Employee name or code, request id (LR-0062 or 62) or the reason text. */
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'department')]
    public ?int $departmentId = null;

    #[Url(as: 'type')]
    public ?int $leaveTypeId = null;

    /** standard | combined */
    #[Url(as: 'mode')]
    public string $mode = '';

    #[Url(as: 'year')]
    public ?int $leaveYearId = null;

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    /** Only requests with unpaid days (Leave Without Pay). */
    #[Url(as: 'unpaid')]
    public bool $unpaidOnly = false;

    public function mount(): void
    {
        abort_unless(app(LeaveRequestAccess::class)->canBrowse(Auth::user()), 403);

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'all';
        }
    }

    public function updating(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'all';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'departmentId', 'leaveTypeId', 'mode', 'leaveYearId', 'from', 'to', 'unpaidOnly']);
        $this->resetPage();
    }

    /** @return array<string, int> */
    #[Computed]
    public function tabCounts(): array
    {
        $counts = $this->filtered(withTab: false)->toBase()
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(self::TABS)->map(fn (?array $statuses) => (int) ($statuses === null
            ? $counts->sum()
            : $counts->only($statuses)->sum()))->all();
    }

    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        return $this->filtered()
            ->with(['employee.user', 'employee.department', 'employee.manager', 'leaveType' => fn ($q) => $q->withTrashed(), 'reviewer', 'hrReviewer'])
            ->latest('start_date')->latest('id')
            ->paginate(20);
    }

    public function render()
    {
        $queue = app(ApprovalQueueService::class);

        return view('livewire.time-off.leave-requests', [
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'leaveTypes' => LeaveType::withTrashed()->orderBy('name')->get(['id', 'name', 'availability', 'deleted_at']),
            'leaveYears' => LeaveYear::orderByDesc('starts_on')->get(),
            // Shown on a pending row: who holds it now.
            'approverOf' => fn (LeaveRequest $r) => in_array($r->status, ['pending', 'pending_hr', 'more_info_requested'], true) ? $queue->path($r)['current'] : (($r->hrReviewer ?? $r->reviewer)?->name),
            'canFileForEmployee' => LeavePolicyEngine::enabled() && Auth::user()->hasPermission('apply_leave_on_behalf'),
        ])->layout('layouts.app', ['title' => 'Leave Requests']);
    }

    private function filtered(bool $withTab = true): Builder
    {
        $ids = app(LeaveRequestAccess::class)->employeeIds(Auth::user());
        $year = $this->leaveYearId ? LeaveYear::find($this->leaveYearId) : null;
        $term = trim($this->search);
        $requestId = preg_match('/^(?:LR-?)?0*(\d+)$/i', $term, $m) ? (int) $m[1] : null;

        return LeaveRequest::query()
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('employee_id', $ids))
            ->when($withTab && self::TABS[$this->tab] !== null, fn (Builder $q) => $q->whereIn('status', self::TABS[$this->tab]))
            ->when($term !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($term, $requestId) {
                $w->where('reason', 'like', "%{$term}%")
                    ->orWhereHas('employee', fn (Builder $e) => $e->where('employee_id', 'like', "%{$term}%")
                        ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$term}%")));
                if ($requestId !== null) {
                    $w->orWhere('id', $requestId);
                }
            }))
            ->when($this->departmentId, fn (Builder $q, int $id) => $q->whereHas('employee', fn (Builder $e) => $e->where('department_id', $id)))
            ->when($this->leaveTypeId, fn (Builder $q, int $id) => $q->where(fn (Builder $w) => $w->where('leave_type_id', $id)->orWhere('balance_leave_type_id', $id)))
            ->when($this->mode === 'combined', fn (Builder $q) => $q->where('request_mode', LeaveRequest::MODE_COMBINED))
            ->when($this->mode === 'standard', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('request_mode')->orWhere('request_mode', '!=', LeaveRequest::MODE_COMBINED)))
            ->when($year, fn (Builder $q, LeaveYear $y) => $q->whereDate('start_date', '<=', $y->ends_on->toDateString())->whereDate('end_date', '>=', $y->starts_on->toDateString()))
            ->when($this->validDate($this->from), fn (Builder $q) => $q->whereDate('end_date', '>=', $this->from))
            ->when($this->validDate($this->to), fn (Builder $q) => $q->whereDate('start_date', '<=', $this->to))
            ->when($this->unpaidOnly, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('lwp_days', '>', 0)
                ->orWhereHas('leaveType', fn (Builder $t) => $t->withTrashed()->where('is_paid', false))));
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }
}
