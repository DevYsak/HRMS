<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveEncashmentNotification;
use App\Services\LeaveService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class MyTimeOff extends Component
{
    use WithPagination;

    // ---- Leave Request ----
    public bool $showRequestModal = false;

    public string $leave_type_id = '';

    public string $start_date = '';

    public string $end_date = '';

    public bool $is_half_day = false;

    public string $reason = '';

    // ---- Filters ----
    public string $filterStatus = '';

    public string $filterTypeId = '';

    public string $filterYear = '';

    public function mount(): void
    {
        $this->filterYear = (string) now()->year;
    }

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFilterTypeId(): void
    {
        $this->resetPage();
    }

    // ---- Leave Encashment ----
    public bool $showEncashModal = false;

    public string $encash_leave_type_id = '';

    public float $encash_days = 0;

    protected function rules(): array
    {
        return [
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|min:5',
            'encash_leave_type_id' => 'required_if:showEncashModal,true|exists:leave_types,id',
            'encash_days' => 'required_if:showEncashModal,true|numeric|min:0.5',
        ];
    }

    // ================================================================
    // Leave Request Actions
    // ================================================================

    public function openRequestModal(): void
    {
        $this->reset(['leave_type_id', 'start_date', 'end_date', 'is_half_day', 'reason']);
        $this->showRequestModal = true;
    }

    public function submitRequest(): void
    {
        $this->validateOnly('leave_type_id,start_date,end_date,reason');

        $employee = Auth::user()->employee;

        if (! $employee) {
            \Flux::toast('You do not have an active employee profile. Contact HR.', variant: 'danger');
            $this->showRequestModal = false;

            return;
        }

        $leaveType = LeaveType::find($this->leave_type_id);
        try {
            app(LeaveService::class)->submitRequest(
                $employee,
                $leaveType,
                $this->start_date,
                $this->end_date,
                $this->reason,
                $this->is_half_day,
            );
        } catch (\DomainException $exception) {
            // Surface service-level errors as a modal-level message so it's obvious
            // (e.g. insufficient balance). Attach to a dedicated 'request' key.
            $this->addError('request', $exception->getMessage());

            return;
        }

        $this->showRequestModal = false;
        \Flux::toast('Leave request submitted successfully.');
        $this->resetPage();
    }

    // ================================================================
    // Leave Encashment Actions
    // ================================================================

    public function openEncashModal(): void
    {
        $this->reset(['encash_leave_type_id', 'encash_days']);
        $this->showEncashModal = true;
    }

    public function submitEncashment(): void
    {
        $this->validateOnly('encash_leave_type_id,encash_days');

        $employee = Auth::user()->employee;

        if (! $employee) {
            \Flux::toast('No employee profile found. Contact HR.', variant: 'danger');
            $this->showEncashModal = false;

            return;
        }

        // Ensure leave type allows encashment
        $leaveType = LeaveType::find($this->encash_leave_type_id);
        if (! $leaveType?->allow_encashment) {
            $this->addError('encash_leave_type_id', 'This leave type does not allow encashment.');

            return;
        }

        // Ensure enough balance to encash
        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $this->encash_leave_type_id)
            ->whereYear('year', now()->year)
            ->first();

        $available = $balance ? max(0, $balance->allocated_days - $balance->used_days - $balance->encashed_days) : 0;

        if ($this->encash_days > $available) {
            $this->addError('encash_days', "Only {$available} days are available for encashment.");

            return;
        }

        // Check if there's already a pending encashment for this type this year
        $alreadyPending = LeaveEncashment::where('employee_id', $employee->id)
            ->where('leave_type_id', $this->encash_leave_type_id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereYear('created_at', now()->year)
            ->exists();

        if ($alreadyPending) {
            $this->addError('encash_leave_type_id', 'You already have a pending or approved encashment for this leave type this year.');

            return;
        }

        $encashment = LeaveEncashment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->encash_leave_type_id,
            'requested_days' => $this->encash_days,
            'status' => 'pending',
            'payout_month' => now()->format('Y-m'),
        ]);

        // Reserve the days in the balance
        if ($balance) {
            $balance->increment('encashed_days', $this->encash_days);
        }

        // Notify Finance & HR about the encashment request
        $encashment->load(['employee.user', 'leaveType']);
        User::whereIn('role', ['finance', 'hr_admin', 'super_admin'])
            ->each(fn ($u) => $u->notify(new LeaveEncashmentNotification($encashment, 'submitted')));

        $this->showEncashModal = false;
        \Flux::toast('Encashment request submitted. Pending HR/Finance approval.');
    }

    // ================================================================
    // Render
    // ================================================================

    public function render()
    {
        $employee = Auth::user()->employee;

        $balances = $employee
            ? $employee->leaveBalances()->with('leaveType')->where('year', $this->filterYear ?: now()->year)->get()
            : collect();

        $requests = $employee
            ? $employee->leaveRequests()->with(['leaveType', 'reviewer'])
                ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
                ->when($this->filterTypeId, fn ($q) => $q->where('leave_type_id', $this->filterTypeId))
                ->latest()->paginate(8)
            : new LengthAwarePaginator([], 0, 8);

        // Encashable leave types (current employee has balance & type allows encashment)
        $encashableTypes = $employee
            ? LeaveType::where('allow_encashment', true)
                ->whereHas('leaveBalances', fn ($q) => $q->where('employee_id', $employee->id))
                ->get()
            : collect();

        $encashments = $employee
            ? LeaveEncashment::where('employee_id', $employee->id)->with('leaveType')->latest()->get()
            : collect();

        // --- Additional UI data for enhanced analytics ---
        $year = now()->year;

        // Requests overlapping the current year for the analytics
        $requestsForYear = $employee
            ? $employee->leaveRequests()->with('leaveType')
                ->where(function ($q) use ($year) {
                    $q->whereYear('start_date', $year)
                        ->orWhereYear('end_date', $year)
                        ->orWhere(function ($q2) {
                            // Requests that span across the year boundary
                            $q2->where('start_date', '<', now()->startOfYear())
                                ->where('end_date', '>', now()->endOfYear());
                        });
                })->get()
            : collect();

        // Weekly pattern (Mon-Sun): count of leave days taken per weekday this year
        $weeklyPattern = array_fill(0, 7, 0); // 0=Mon ... 6=Sun
        // Monthly stats (Jan..Dec) - number of leave days per month
        $monthlyStats = array_fill(0, 12, 0);

        foreach ($requestsForYear as $r) {
            if ($r->status !== 'approved') {
                continue;
            }

            $start = $r->start_date->copy();
            $end = $r->end_date->copy();

            $yearStart = now()->startOfYear();
            $yearEnd = now()->endOfYear();

            if ($start->lt($yearStart)) {
                $start = $yearStart->copy();
            }
            if ($end->gt($yearEnd)) {
                $end = $yearEnd->copy();
            }

            $daysCount = min($start->diffInDays($end) + 1, 366);

            for ($i = 0; $i < $daysCount; $i++) {
                $cursor = $start->copy()->addDays($i);
                $dow = $cursor->dayOfWeekIso; // 1..7 (Mon..Sun)
                $weeklyPattern[$dow - 1]++;
                $monthlyStats[$cursor->month - 1]++;
            }
        }

        // Find CSL (casual), Comp Off and MDL balances (best-effort matching)
        $findBalance = function ($keywords) use ($balances) {
            foreach ($balances as $b) {
                $name = strtolower($b->leaveType->name ?? '');
                foreach ((array) $keywords as $kw) {
                    if (str_contains($name, strtolower($kw))) {
                        return $b;
                    }
                }
            }

            return null;
        };

        $csl = $findBalance(['casual', 'csl', 'casual leave']);
        $compOff = $findBalance(['comp', 'compensatory', 'comp off']);
        $mdl = $findBalance(['md', 'medical', 'mdl', 'maternity']);

        // Fallback: choose first balances if any are missing
        $firstBalances = $balances->values();
        if (! $csl && $firstBalances->count() > 0) {
            $csl = $firstBalances->get(0);
        }
        if (! $compOff && $firstBalances->count() > 1) {
            $compOff = $firstBalances->get(1) ?? $firstBalances->get(0);
        }
        if (! $mdl && $firstBalances->count() > 2) {
            $mdl = $firstBalances->get(2) ?? $firstBalances->get(0);
        }

        // CSL donut: used vs remaining
        $cslUsed = $csl ? ($csl->used_days + ($csl->encashed_days ?? 0)) : 0;
        $cslTotal = $csl ? max(0, $csl->allocated_days) : 0;
        $cslRemaining = max(0, $cslTotal - $cslUsed);

        return view('livewire.time-off.my-time-off', [
            'balances' => $balances,
            'requests' => $requests,
            'leaveTypes' => LeaveType::all(),
            'encashableTypes' => $encashableTypes,
            'encashments' => $encashments,
            'pendingCount' => $employee ? $employee->leaveRequests()->where('status', 'pending')->count() : 0,
            'weeklyPattern' => $weeklyPattern,
            'monthlyStats' => $monthlyStats,
            'cslData' => [
                'used' => (float) $cslUsed,
                'remaining' => (float) $cslRemaining,
                'label' => $csl?->leaveType->name ?? 'CSL',
                'color' => $csl?->leaveType->color ?? '#f59e0b',
            ],
            'highlightBalances' => [
                'csl' => $csl,
                'compOff' => $compOff,
                'mdl' => $mdl,
            ],
        ])->layout('layouts.app', ['title' => 'My Time Off']);
    }
}
