<?php

namespace App\Livewire\TimeOff;

use App\Models\DecemberMandatoryDay;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Notifications\LeaveEncashmentNotification;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class MyTimeOff extends Component
{
    use WithFileUploads;
    use WithPagination;

    // ---- Leave Request ----
    public bool $showRequestModal = false;

    public string $leave_type_id = '';

    public string $start_date = '';

    public string $end_date = '';

    public bool $is_half_day = false;

    public string $half_day_period = '';

    public string $requested_leave_status = 'paid';

    public string $reason = '';

    public string $employee_remarks = '';

    public $attachment = null;

    // ---- Filters ----
    public string $filterStatus = '';

    public string $filterTypeId = '';

    public string $filterYear = '';

    public string $search = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    // ---- Calendar ----
    public string $calendarMonth = '';

    // ---- Leave Encashment ----
    public bool $showEncashModal = false;

    public string $encash_leave_type_id = '';

    public float $encash_days = 0;

    public function mount(): void
    {
        $this->filterYear = (string) now()->year;
        $this->calendarMonth = now()->format('Y-m');
    }

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFilterTypeId(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function previousCalendarMonth(): void
    {
        $this->calendarMonth = \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->calendarMonth)->subMonth()->format('Y-m');
    }

    public function nextCalendarMonth(): void
    {
        $this->calendarMonth = \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->calendarMonth)->addMonth()->format('Y-m');
    }

    public function goToCurrentCalendarMonth(): void
    {
        $this->calendarMonth = now()->format('Y-m');
    }

    /** When the half-day toggle is turned off, clear the period selection. */
    public function updatedIsHalfDay(bool $value): void
    {
        if (! $value) {
            $this->half_day_period = '';
        }
    }

    /** When leave type changes, default to paid if allowed, else unpaid. */
    public function updatedLeaveTypeId(string $value): void
    {
        $type = LeaveType::find($value);
        if ($type) {
            if ($type->allow_paid_request) {
                $this->requested_leave_status = 'paid';
            } elseif ($type->allow_unpaid_request) {
                $this->requested_leave_status = 'unpaid';
            }
        }
    }

    protected function rules(): array
    {
        $leaveType = LeaveType::find($this->leave_type_id);

        return [
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_half_day' => 'boolean',
            'half_day_period' => 'required_if:is_half_day,true|nullable|in:first_half,second_half',
            'requested_leave_status' => 'required|in:paid,unpaid',
            'reason' => 'required|min:5',
            'employee_remarks' => 'nullable|string|max:1000',
            'attachment' => ($leaveType?->attachment_required ? 'required' : 'nullable')
                .'|nullable|file|max:5120|mimes:pdf,jpg,jpeg,png',
            'encash_leave_type_id' => $this->showEncashModal
                ? 'required|exists:leave_types,id'
                : 'nullable',
            'encash_days' => $this->showEncashModal
                ? 'required|numeric|min:0.5'
                : 'nullable',
        ];
    }

    // ================================================================
    // Leave Request Actions
    // ================================================================

    public function openRequestModal(): void
    {
        $this->reset([
            'leave_type_id', 'start_date', 'end_date', 'is_half_day',
            'half_day_period', 'requested_leave_status', 'reason',
            'employee_remarks', 'attachment',
        ]);
        $this->resetErrorBag();
        $this->resetValidation();
        $this->requested_leave_status = 'paid';
        $this->showRequestModal = true;
    }

    public function closeRequestModal(): void
    {
        $this->showRequestModal = false;
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function submitRequest(): void
    {
        $this->validate();

        $employee = Auth::user()->employee;

        if (! $employee) {
            \Flux::toast('You do not have an active employee profile. Contact HR.', variant: 'danger');
            $this->closeRequestModal();

            return;
        }

        $leaveType = LeaveType::findOrFail($this->leave_type_id);

        // Store attachment
        $attachmentPath = null;
        if ($this->attachment) {
            $attachmentPath = $this->attachment->store('leave-attachments', 'public');
        }

        try {
            app(LeaveService::class)->submitRequest(
                $employee,
                $leaveType,
                $this->start_date,
                $this->end_date,
                $this->reason,
                $this->is_half_day,
                $this->half_day_period ?: null,
                $this->requested_leave_status,
                $attachmentPath,
                $this->employee_remarks ?: null,
            );
        } catch (\DomainException $exception) {
            $this->addError('request', $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            Log::error('Leave request submission failed.', [
                'employee_id' => $employee->id,
                'leave_type_id' => $this->leave_type_id,
                'start_date' => $this->start_date,
                'end_date' => $this->end_date,
                'message' => $exception->getMessage(),
            ]);

            $this->addError('request', 'Unable to submit the leave request right now. Please try again.');

            return;
        }

        $this->closeRequestModal();
        \Flux::toast('Leave request submitted successfully.');
        $this->resetPage();
    }

    // ================================================================
    // Cancel Leave Request
    // ================================================================

    public function cancelRequest(int $id): void
    {
        $employee = Auth::user()->employee;

        if (! $employee) {
            return;
        }

        $request = $employee->leaveRequests()->findOrFail($id);

        try {
            app(LeaveService::class)->cancelRequest($request);
            \Flux::toast('Leave request cancelled.');
            $this->resetPage();
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }
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

        $leaveType = LeaveType::find($this->encash_leave_type_id);
        if (! $leaveType?->allow_encashment) {
            $this->addError('encash_leave_type_id', 'This leave type does not allow encashment.');

            return;
        }

        // Block duplicate in-flight requests (pending, pending_finance, or approved)
        $alreadyPending = LeaveEncashment::where('employee_id', $employee->id)
            ->where('leave_type_id', $this->encash_leave_type_id)
            ->whereIn('status', ['pending', 'pending_finance', 'approved'])
            ->whereYear('created_at', now()->year)
            ->exists();

        if ($alreadyPending) {
            $this->addError('encash_leave_type_id', 'You already have an active encashment request for this leave type this year.');

            return;
        }

        try {
            $encashment = app(LeaveService::class)->requestEncashment(
                $employee,
                $leaveType,
                (float) $this->encash_days,
                now()->format('Y-m')
            );
        } catch (\DomainException $e) {
            $this->addError('encash_days', $e->getMessage());

            return;
        }

        $encashment->load(['employee.user', 'leaveType']);
        User::whereIn('role', ['hr_admin', 'super_admin'])
            ->each(fn ($u) => $u->notify(new LeaveEncashmentNotification($encashment, 'submitted')));

        $this->showEncashModal = false;
        \Flux::toast('Encashment request submitted. Pending HR approval.');
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
            ? $employee->leaveRequests()->with(['leaveType', 'reviewer', 'hrReviewer', 'paymentAuditLogs.changedByUser'])
                ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
                ->when($this->filterTypeId, fn ($q) => $q->where('leave_type_id', $this->filterTypeId))
                ->when($this->search, fn ($q) => $q->where(function ($q2) {
                    $q2->where('reason', 'like', '%'.$this->search.'%')
                        ->orWhereHas('leaveType', fn ($q3) => $q3->where('name', 'like', '%'.$this->search.'%'));
                }))
                ->orderBy($this->sortField, $this->sortDirection)
                ->paginate(8)
            : new LengthAwarePaginator([], 0, 8);

        $encashableTypes = $employee
            ? LeaveType::where('allow_encashment', true)
                ->whereHas('leaveBalances', fn ($q) => $q->where('employee_id', $employee->id)->where('year', now()->year))
                ->get()
                ->map(function ($type) use ($employee) {
                    $balance = LeaveBalance::where('employee_id', $employee->id)
                        ->where('leave_type_id', $type->id)
                        ->where('year', now()->year)
                        ->first();

                    $cfAvailable = $balance
                        ? max(0, (float) $balance->carried_forward_days - (float) ($balance->encashed_days ?? 0))
                        : 0.0;

                    $cyAvailable = 0.0;
                    if ($type->allow_current_year_encashment && $balance) {
                        $cyAvailable = max(0, (float) $balance->allocated_days - (float) $balance->used_days - (float) ($balance->encashed_days ?? 0));
                    }

                    $type->available_for_encashment = $cfAvailable + $cyAvailable;
                    $type->cf_available = $cfAvailable;
                    $type->cy_available = $cyAvailable;

                    // Remaining cap
                    if ($type->max_encashable_days !== null) {
                        $alreadyEncashed = LeaveEncashment::where('employee_id', $employee->id)
                            ->where('leave_type_id', $type->id)
                            ->whereNotIn('status', ['rejected'])
                            ->whereYear('created_at', now()->year)
                            ->sum('requested_days');
                        $type->remaining_cap = max(0, $type->max_encashable_days - $alreadyEncashed);
                    } else {
                        $type->remaining_cap = null;
                    }

                    return $type;
                })
                ->filter(fn ($type) => $type->available_for_encashment > 0)
            : collect();

        $encashments = $employee
            ? LeaveEncashment::where('employee_id', $employee->id)->with('leaveType')->latest()->get()
            : collect();

        $year = now()->year;

        $requestsForYear = $employee
            ? $employee->leaveRequests()->with('leaveType')
                ->where(function ($q) use ($year) {
                    $q->whereYear('start_date', $year)
                        ->orWhereYear('end_date', $year)
                        ->orWhere(fn ($q2) => $q2
                            ->where('start_date', '<', now()->startOfYear())
                            ->where('end_date', '>', now()->endOfYear())
                        );
                })->get()
            : collect();

        $weeklyPattern = array_fill(0, 7, 0);
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
                $dow = $cursor->dayOfWeekIso;
                $weeklyPattern[$dow - 1]++;
                $monthlyStats[$cursor->month - 1]++;
            }
        }

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

        $cslUsed = $csl ? ($csl->used_days + ($csl->encashed_days ?? 0)) : 0;
        $cslTotal = $csl ? max(0, $csl->allocated_days) : 0;
        $cslRemaining = max(0, $cslTotal - $cslUsed);

        // Active leave types the employee can request
        $selectedType = $this->leave_type_id ? LeaveType::find($this->leave_type_id) : null;
        $selectedBalance = ($selectedType && $employee)
            ? $balances->firstWhere('leave_type_id', $selectedType->id)
            : null;

        // ── Calendar Widget ──────────────────────────────────────────────
        $calendarCursor = \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->calendarMonth ?: now()->format('Y-m'))->startOfMonth();
        $monthStart = $calendarCursor->copy()->startOfMonth();
        $monthEnd = $calendarCursor->copy()->endOfMonth();
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        $holidays = PublicHoliday::whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get()
            ->keyBy(fn ($h) => $h->date->toDateString());

        $calendarRequests = $employee
            ? $employee->leaveRequests()
                ->whereIn('status', ['approved', 'pending', 'pending_hr'])
                ->where('start_date', '<=', $gridEnd)
                ->where('end_date', '>=', $gridStart)
                ->with('leaveType')
                ->get()
            : collect();

        $calendarDays = [];
        $cursor = $gridStart->copy();
        while ($cursor->lte($gridEnd)) {
            $dayRequests = $calendarRequests->filter(
                fn ($r) => $cursor->between($r->start_date->copy()->startOfDay(), $r->end_date->copy()->startOfDay())
            );

            $calendarDays[] = [
                'date' => $cursor->copy(),
                'isCurrentMonth' => $cursor->month === $monthStart->month,
                'isToday' => $cursor->isToday(),
                'isWeekend' => $cursor->isWeekend(),
                'holiday' => $holidays->get($cursor->toDateString()),
                'approved' => $dayRequests->firstWhere('status', 'approved'),
                'pending' => $dayRequests->first(fn ($r) => in_array($r->status, ['pending', 'pending_hr'], true)),
            ];

            $cursor->addDay();
        }

        // ── Leave forecast: project full-year usage from the current run rate ──
        $monthsElapsed = max(1, now()->month);
        $projectedYearEnd = round(($cslUsed / $monthsElapsed) * 12, 1);
        $forecast = [
            'used' => (float) $cslUsed,
            'allocated' => (float) $cslTotal,
            'projected' => $projectedYearEnd,
            'on_track' => $cslTotal <= 0 || $projectedYearEnd <= $cslTotal,
            'label' => $csl?->leaveType->name ?? 'CSL',
        ];

        // ── Holiday planner: upcoming public holidays + December mandatory days ──
        $upcomingHolidays = PublicHoliday::whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->limit(8)
            ->get();
        $mandatoryDays = DecemberMandatoryDay::where('year', now()->year)
            ->orderBy('date')
            ->get();

        return view('livewire.time-off.my-time-off', [
            'balances' => $balances,
            'requests' => $requests,
            'leaveTypes' => LeaveType::where(function ($q) {
                $q->where('allow_paid_request', true)->orWhere('allow_unpaid_request', true);
            })->orderBy('name')->get()->unique('name')->values(),
            'encashableTypes' => $encashableTypes,
            'encashments' => $encashments,
            'pendingCount' => $employee ? $employee->leaveRequests()->whereIn('status', ['pending', 'pending_hr'])->count() : 0,
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
            'selectedType' => $selectedType,
            'selectedBalance' => $selectedBalance,
            'calendarDays' => $calendarDays,
            'calendarLabel' => $monthStart->format('F Y'),
            'forecast' => $forecast,
            'upcomingHolidays' => $upcomingHolidays,
            'mandatoryDays' => $mandatoryDays,
        ])->layout('layouts.app', ['title' => 'My Time Off']);
    }
}
