<?php

namespace App\Livewire\TimeOff;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveCarryForwardTransaction as Transaction;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Year-end carry forward.
 *
 * Preview first, always. Carrying leave changes what people are entitled to,
 * and the only safe way to run it is to see the whole list before any of it is
 * applied — so nothing here writes until HR asks it to, per row or in bulk.
 *
 * The numbers are not calculated here. LeaveCarryForwardService sits on top of
 * the existing carry-over engine and this screen renders what it returns.
 */
class LeaveCarryForward extends Component
{
    public ?int $currentYearId = null;

    public ?int $previousYearId = null;

    public ?int $departmentId = null;

    /**
     * Pre-filled when HR arrives from an employee's own Leave tab, so the
     * person does not have to be searched for a second time.
     */
    #[Url(as: 'employeeId')]
    public ?int $employeeId = null;

    public ?int $leaveTypeId = null;

    public string $statusFilter = '';

    /** Row being carried at less than the full eligible amount. */
    public ?int $partialFor = null;

    public ?float $partialDays = null;

    public string $partialReason = '';

    /** Row being reversed. A reversal needs a reason, so it needs a prompt. */
    public ?int $reverseId = null;

    public string $reverseReason = '';

    public function mount(LeaveYearResolver $resolver): void
    {
        $this->authorize('view_leave_carry_forward');

        $current = $resolver->current();
        $this->currentYearId = $current->id;
        // Resolved rather than chosen: the previous year is a fact about the
        // current one, not a preference.
        $this->previousYearId = $resolver->previous($current)->id;
    }

    public function updated(): void
    {
        $this->partialFor = null;
        $this->reverseId = null;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getRowsProperty(): Collection
    {
        if (! $this->currentYearId || ! $this->previousYearId) {
            return collect();
        }

        return app(LeaveCarryForwardService::class)->preview(
            LeaveYear::find($this->previousYearId),
            LeaveYear::find($this->currentYearId),
            [
                'department_id' => $this->departmentId,
                'employee_id' => $this->employeeId,
                'leave_type_id' => $this->leaveTypeId,
                'status' => $this->statusFilter ?: null,
            ],
        );
    }

    /** @return array<string, float|int> */
    public function getTotalsProperty(): array
    {
        $rows = $this->rows;

        return [
            'rows' => $rows->count(),
            'employees' => $rows->pluck('employee_id')->unique()->count(),
            'eligible' => round($rows->sum('eligible'), 2),
            'applied' => round($rows->sum('applied'), 2),
            'outstanding' => round($rows->sum('remaining_eligible'), 2),
        ];
    }

    /**
     * Whether the engine found anything at all for this selection.
     *
     * Distinct from "everything is already carried". Zero eligible rows means
     * there is no previous-year data to work from; zero outstanding days means
     * the work is done. Reporting the second when the first is true tells HR
     * the operation succeeded when it never ran.
     */
    public function getHasEligibleRowsProperty(): bool
    {
        return $this->rows->isNotEmpty();
    }

    /** Days still eligible but not yet carried, across the current selection. */
    public function getOutstandingDaysProperty(): float
    {
        return round($this->rows->sum('remaining_eligible'), 2);
    }

    /**
     * Rows the system can actually compute an amount for.
     *
     * A row whose closed year has no usage figure is real and must be shown,
     * but nothing may be applied to it in bulk — HR decides that amount one
     * employee at a time. Without this the screen would see rows with zero
     * outstanding days and report the work as finished.
     */
    public function getHasDerivableRowsProperty(): bool
    {
        return $this->rows->contains(fn (array $row) => ($row['figures_known'] ?? true) === true);
    }

    /** Rows waiting on an HR decision because their figures are unknown. */
    public function getUndecidedRowCountProperty(): int
    {
        return $this->rows->filter(fn (array $row) => ($row['figures_known'] ?? true) === false
            && ($row['applied'] ?? 0) <= 0)->count();
    }

    public function applyRow(int $employeeId, int $leaveTypeId): void
    {
        $this->authorize('manage_leave_carry_forward');

        $this->runApply($employeeId, $leaveTypeId, null, null);
    }

    public function startPartial(int $employeeId, int $leaveTypeId, float $eligible): void
    {
        $this->authorize('manage_leave_carry_forward');

        $this->partialFor = $employeeId * 1000000 + $leaveTypeId;
        $this->partialDays = $eligible;
        $this->partialReason = '';
    }

    public function confirmPartial(int $employeeId, int $leaveTypeId): void
    {
        $this->authorize('manage_leave_carry_forward');

        $this->runApply($employeeId, $leaveTypeId, $this->partialDays, $this->partialReason ?: null);
        $this->partialFor = null;
    }

    public function applyAll(): void
    {
        $this->authorize('manage_leave_carry_forward');

        // Guarded here as well as in the view. A disabled button is a courtesy;
        // this is what actually stops an empty run, and it is what a test can
        // hold onto.
        if (! $this->hasEligibleRows) {
            $previous = LeaveYear::find($this->previousYearId)?->label ?? 'the previous leave year';

            \Flux::toast("No eligible leave is available to carry forward for {$previous}.", variant: 'warning');

            return;
        }

        if (! $this->hasDerivableRows) {
            \Flux::toast(
                'Historical used days are not available for these employees. Enter the approved carry-forward days for each one — the system cannot derive them.',
                variant: 'warning',
            );

            return;
        }

        if ($this->outstandingDays <= 0) {
            \Flux::toast('All eligible leave has already been carried forward.');

            return;
        }

        $result = app(LeaveCarryForwardService::class)->applyAll(
            LeaveYear::find($this->previousYearId),
            LeaveYear::find($this->currentYearId),
            auth()->user(),
            [
                'department_id' => $this->departmentId,
                'employee_id' => $this->employeeId,
                'leave_type_id' => $this->leaveTypeId,
            ],
        );

        \Flux::toast(
            $result['applied'] === 0
                ? 'All eligible leave has already been carried forward.'
                : "Carried {$result['days']} days forward across {$result['applied']} rows."
                    .($result['skipped'] > 0 ? " {$result['skipped']} already applied." : '')
        );
    }

    public function startReverse(int $transactionId): void
    {
        $this->authorize('manage_leave_carry_forward');

        $this->reverseId = $transactionId;
        $this->reverseReason = '';
    }

    public function confirmReverse(): void
    {
        $this->authorize('manage_leave_carry_forward');

        $tx = Transaction::findOrFail($this->reverseId);

        try {
            app(LeaveCarryForwardService::class)->reverse($tx, auth()->user(), $this->reverseReason);
        } catch (RuntimeException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->reverseId = null;
        \Flux::toast('Carry forward reversed. The record and its history are kept.');
    }

    private function runApply(int $employeeId, int $leaveTypeId, ?float $days, ?string $reason): void
    {
        try {
            $tx = app(LeaveCarryForwardService::class)->apply(
                Employee::findOrFail($employeeId),
                LeaveType::findOrFail($leaveTypeId),
                LeaveYear::findOrFail($this->previousYearId),
                LeaveYear::findOrFail($this->currentYearId),
                auth()->user(),
                $days,
                $reason,
            );
        } catch (RuntimeException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast(
            $tx->remainingEligible() > 0
                ? "Carried {$tx->applied_days} of {$tx->eligible_days} eligible days forward. {$tx->remainingEligible()} remain eligible."
                : "Carried {$tx->applied_days} days forward."
        );
    }

    public function render()
    {
        return view('livewire.time-off.leave-carry-forward', [
            'rows' => $this->rows,
            'totals' => $this->totals,
            'hasEligibleRows' => $this->hasEligibleRows,
            'outstandingDays' => $this->outstandingDays,
            'hasDerivableRows' => $this->hasDerivableRows,
            'undecidedRowCount' => $this->undecidedRowCount,
            'leaveYears' => LeaveYear::orderByDesc('starts_on')->get(),
            'departments' => Department::orderBy('name')->get(),
            'leaveTypes' => LeaveType::where('allow_carry_forward', true)->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Leave Carry Forward']);
    }
}
