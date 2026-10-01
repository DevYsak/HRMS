<?php

namespace App\Livewire\TimeOff;

use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\Approvals\ApprovalGuard;
use App\Services\AttendanceService;
use App\Services\Leave\LeaveRegularisationService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Leave regularisation — a queue over the existing regularisation pipeline.
 *
 * This screen raises and reviews requests; it decides nothing itself. Approval
 * goes through AttendanceService, which owns the manager → HR → admin chain
 * for every kind of regularisation, so a leave correction cannot take a
 * shortcut that an attendance correction does not have.
 */
class LeaveRegularisation extends Component
{
    use WithPagination;

    public string $statusFilter = 'pending';

    /** Pre-filled when HR arrives from an employee's own Leave tab. */
    #[Url(as: 'employeeId')]
    public ?int $employeeFilter = null;

    /** Raise-a-request form. */
    public bool $showForm = false;

    public ?int $formEmployeeId = null;

    public ?int $formLeaveTypeId = null;

    public string $formFrom = '';

    public string $formTo = '';

    public ?float $formDuration = null;

    public string $formReason = '';

    public string $formRemarks = '';

    /** Review prompt — rejecting requires a comment, so it needs a field. */
    #[Locked]
    public ?int $reviewId = null;

    public string $reviewComment = '';

    public function mount(): void
    {
        $this->authorize('view_leave_regularisation');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingEmployeeFilter(): void
    {
        $this->resetPage();
    }

    /** @return array<string, int> */
    public function getCountsProperty(): array
    {
        $base = fn () => $this->inReach(AttendanceRegularisation::where('category', 'leave'));

        return [
            'pending' => $base()->where('status', 'pending')->count(),
            'approved' => $base()->where('status', 'approved')->count(),
            'rejected' => $base()->where('status', 'rejected')->count(),
            'cancelled' => $base()->where('status', 'cancelled')->count(),
            'this_month' => $base()->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];
    }

    public function openForm(): void
    {
        $this->authorize('create_leave_regularisation');

        $this->reset(['formEmployeeId', 'formLeaveTypeId', 'formFrom', 'formTo', 'formDuration', 'formReason', 'formRemarks']);
        $this->showForm = true;
    }

    public function submitRequest(): void
    {
        $this->authorize('create_leave_regularisation');

        $this->validate([
            'formEmployeeId' => ['required', 'exists:employees,id'],
            'formLeaveTypeId' => ['required', 'exists:leave_types,id'],
            'formFrom' => ['required', 'date'],
            'formTo' => ['required', 'date'],
            'formReason' => ['required', 'string', 'min:3'],
        ]);

        try {
            $employee = Employee::findOrFail($this->formEmployeeId);
            app(ApprovalGuard::class)->assertCanView(auth()->user(), $employee);

            app(LeaveRegularisationService::class)->submit(
                employee: $employee,
                type: LeaveType::findOrFail($this->formLeaveTypeId),
                from: Carbon::parse($this->formFrom),
                to: Carbon::parse($this->formTo),
                reason: $this->formReason,
                requestedBy: auth()->user(),
                duration: $this->formDuration,
                remarks: $this->formRemarks ?: null,
            );
        } catch (RuntimeException $e) {
            // Written to be read by the requester, so shown as-is.
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->showForm = false;
        \Flux::toast('Regularisation submitted for approval.');
    }

    public function approve(int $id): void
    {
        $this->authorize('approve_leave_regularisation');

        $reg = AttendanceRegularisation::findOrFail($id);
        app(AttendanceService::class)->approveRegularisation($reg, auth()->id(), $this->reviewComment ?: null);

        $fresh = $reg->fresh();
        $fresh->employee?->user?->notify(new RegularisationReviewedNotification($fresh));

        $this->reviewComment = '';
        $this->reviewId = null;

        \Flux::toast($fresh->status === 'approved'
            ? 'Approved. The leave has been deducted and the day marked.'
            : 'Approved at this stage — now with '.$fresh->stageLabel().'.');
    }

    public function startReject(int $id): void
    {
        $this->authorize('approve_leave_regularisation');

        $this->reviewId = $id;
        $this->reviewComment = '';
    }

    public function confirmReject(): void
    {
        $this->authorize('approve_leave_regularisation');

        if (trim($this->reviewComment) === '') {
            \Flux::toast('A rejection needs a reason.', variant: 'danger');

            return;
        }

        $reg = AttendanceRegularisation::findOrFail($this->reviewId);
        app(AttendanceService::class)->rejectRegularisation($reg, auth()->id(), $this->reviewComment);
        $reg->fresh()->employee?->user?->notify(new RegularisationReviewedNotification($reg->fresh()));

        $this->reviewId = null;
        $this->reviewComment = '';
        \Flux::toast('Regularisation rejected.');
    }

    public function cancel(int $id): void
    {
        $reg = AttendanceRegularisation::findOrFail($id);

        // An employee may withdraw their own; anyone managing the queue may
        // withdraw any of them.
        if (! auth()->user()->hasPermission('manage_leave_regularisation')
            && $reg->employee?->user_id !== auth()->id()) {
            abort(403);
        }

        try {
            app(LeaveRegularisationService::class)->cancel($reg, auth()->user());
        } catch (RuntimeException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Regularisation cancelled.');
    }

    /**
     * Employee ids this user may see here — their reporting line / HR scope
     * plus their own record — or null when company-wide.
     *
     * @return array<int, int>|null
     */
    protected function reachableIds(): ?array
    {
        $ids = auth()->user()->accessibleEmployeeIds();

        if ($ids === null) {
            return null;
        }

        $own = auth()->user()->employee?->id;

        return $own ? array_values(array_unique([...$ids, $own])) : $ids;
    }

    /**
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function inReach($query)
    {
        $ids = $this->reachableIds();

        return $query->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids));
    }

    public function render()
    {
        $requests = $this->inReach(AttendanceRegularisation::with(['employee.user', 'leaveType', 'attendance', 'reviewer']))
            ->where('category', 'leave')
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->employeeFilter, fn ($q) => $q->where('employee_id', $this->employeeFilter))
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.time-off.leave-regularisation', [
            'requests' => $requests,
            'counts' => $this->counts,
            'employees' => Employee::with('user')->where('status', 'active')
                ->when($this->reachableIds() !== null, fn ($q) => $q->whereIn('id', $this->reachableIds()))
                ->get(),
            'leaveTypes' => LeaveType::orderBy('name')->get(),
            'windowDays' => (int) config('leave_regularisation.window_days', 30),
        ])->layout('layouts.app', ['title' => 'Leave Regularisation']);
    }
}
