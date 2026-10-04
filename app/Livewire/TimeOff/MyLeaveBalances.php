<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveExpiryService;
use App\Services\Leave\LeaveStatementService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * My Time Off — balances, statements and alerts (Phase 2E).
 *
 * Always the signed-in employee's own data: there is no employee parameter
 * to tamper with. Every figure comes from the ledger (bucket summary,
 * timeline, month-wise statement); HR internal notes are never read.
 */
class MyLeaveBalances extends Component
{
    public ?int $leaveYearId = null;

    /** balances | history | statement | requests */
    public string $panel = 'balances';

    public ?int $historyTypeId = null;

    public ?string $historyMonth = null;

    public ?int $statementTypeId = null;

    public function mount(LeaveYearResolver $years): void
    {
        $this->leaveYearId = $years->current()->id;
    }

    #[Computed]
    public function employee()
    {
        return Auth::user()?->employee;
    }

    #[Computed]
    public function year(): LeaveYear
    {
        return LeaveYear::findOrFail($this->leaveYearId);
    }

    /** @return Collection<int, array{type: LeaveType, summary: array<string, mixed>}> */
    #[Computed]
    public function cards(): Collection
    {
        if (! $this->employee) {
            return collect();
        }

        $calculator = app(LeaveBalanceCalculator::class);

        return LeaveBalance::with('leaveType')
            ->where('employee_id', $this->employee->id)
            ->where(fn ($q) => $q->where('leave_year_id', $this->year->id)->orWhere('year', $this->year->legacyYear()))
            ->get()
            // Retired types (the 28-day Annual Leave) are history, not a
            // balance to apply against; their movements stay in the timeline.
            ->filter(fn (LeaveBalance $b) => $b->leaveType !== null && ! $b->leaveType->trashed())
            ->map(fn (LeaveBalance $b) => ['type' => $b->leaveType, 'summary' => $calculator->summary($b)])
            ->sortBy(fn ($c) => [$c['type']->code === ConexusLeavePolicyService::CSL_CODE ? 0 : 1, $c['type']->name])
            ->values();
    }

    /**
     * The Conexus view of the year — CSL, MDL dates and Comp Off — from the
     * same service the hero and the dashboard read.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function overview(): array
    {
        return app(EmployeeLeaveOverviewService::class)->for($this->employee, $this->year);
    }

    #[Computed]
    public function alerts(): Collection
    {
        return $this->employee
            ? app(LeaveExpiryService::class)->upcoming(60, $this->employee->id)
            : collect();
    }

    #[Computed]
    public function history(): Collection
    {
        return $this->employee
            ? app(LeaveStatementService::class)->timeline($this->employee, $this->year, [
                'leave_type_id' => $this->historyTypeId,
                'month' => $this->historyMonth,
            ])
            : collect();
    }

    #[Computed]
    public function statement(): Collection
    {
        if (! $this->employee) {
            return collect();
        }

        $type = LeaveType::withTrashed()->find($this->statementTypeId ?? $this->cards->first()['type']?->id);

        return $type ? app(LeaveStatementService::class)->monthly($this->employee, $type, $this->year) : collect();
    }

    /** @return array<string, Collection<int, LeaveRequest>|int> */
    #[Computed]
    public function requestGroups(): array
    {
        if (! $this->employee) {
            return [];
        }

        $today = Carbon::today()->toDateString();
        $base = fn () => LeaveRequest::with('leaveType')->where('employee_id', $this->employee->id);

        return [
            'pending' => $base()->whereIn('status', ['pending', 'pending_hr'])->orderBy('start_date')->get(),
            'needs_info' => $base()->where('status', 'more_info_requested')->orderBy('start_date')->get(),
            'upcoming' => $base()->where('status', 'approved')->whereDate('start_date', '>=', $today)->orderBy('start_date')->get(),
            'rejected' => $base()->where('status', 'rejected')
                ->whereDate('start_date', '>=', $this->year->starts_on->toDateString())
                ->whereDate('start_date', '<=', $this->year->ends_on->toDateString())->latest('start_date')->get(),
            'encashment_pending' => LeaveEncashment::where('employee_id', $this->employee->id)->whereIn('status', ['pending', 'pending_finance'])->count(),
        ];
    }

    public function showHistory(int $leaveTypeId): void
    {
        $this->historyTypeId = $leaveTypeId;
        $this->panel = 'history';
    }

    public function showStatement(int $leaveTypeId): void
    {
        $this->statementTypeId = $leaveTypeId;
        $this->panel = 'statement';
    }

    public function apply(int $leaveTypeId): void
    {
        // My Time Off owns the request form; hand it the type.
        $this->dispatch('apply-leave', leaveTypeId: $leaveTypeId);
    }

    public function encash(int $leaveTypeId): void
    {
        // My Time Off owns the encashment form too.
        $this->dispatch('encash-leave', leaveTypeId: $leaveTypeId);
    }

    /** Stage of a request in employee terms. */
    public static function stage(LeaveRequest $request): string
    {
        return match ($request->status) {
            'pending' => 'Awaiting manager',
            'pending_hr' => 'Awaiting HR',
            'more_info_requested' => 'More information requested',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $request->status)),
        };
    }

    public function render()
    {
        $years = $this->employee
            ? LeaveYear::whereIn('id', LeaveBalance::where('employee_id', $this->employee->id)->whereNotNull('leave_year_id')->distinct()->pluck('leave_year_id'))
                ->orWhere('id', $this->leaveYearId)->orderByDesc('starts_on')->get()
            : collect();

        return view('livewire.time-off.my-leave-balances', ['leaveYears' => $years]);
    }
}
