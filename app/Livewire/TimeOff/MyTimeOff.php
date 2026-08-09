<?php

namespace App\Livewire\TimeOff;

use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\HolidayPaySetting;
use App\Models\HolidayWorkRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Models\WfhRequest;
use App\Notifications\LeaveEncashmentNotification;
use App\Services\HolidayWorkService;
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

    // Holiday-work request ("Request to Work on Holiday")
    public bool $showHolidayWork = false;

    public string $hw_date = '';

    public string $hw_reason = '';

    public string $hw_location = 'office';

    public float $hw_hours = 8;

    public string $hw_project = '';

    public string $hw_comments = '';

    public string $hw_pay_type = 'overtime';

    public bool $is_half_day = false;

    public string $half_day_period = '';

    public string $requested_leave_status = 'paid';

    /** Leave Planner widget (independent of the Apply modal). */
    public ?string $planner_start = null;

    public ?string $planner_end = null;

    public string $reason = '';

    public string $employee_remarks = '';

    /** Single supporting document (max 5 MB), previewed before submit. */
    public $attachment = null;

    // ---- Conversation thread (message/attachment back-and-forth with the reviewer) ----
    public ?int $conversationId = null;

    public string $conversation_body = '';

    public $conversation_attachment = null;

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
        return [
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_half_day' => 'boolean',
            'half_day_period' => 'required_if:is_half_day,true|nullable|in:first_half,second_half',
            'requested_leave_status' => 'required|in:paid,unpaid',
            'reason' => 'required|min:5',
            'employee_remarks' => 'nullable|string|max:1000',
            'attachment' => 'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png,webp',
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

    /**
     * Carry the Leave Planner's dates into the Apply modal and open it.
     * Reuses the same fields/validation/calculation as a normal apply.
     */
    public function applyFromPlanner(): void
    {
        $this->reset([
            'leave_type_id', 'is_half_day', 'half_day_period',
            'requested_leave_status', 'reason', 'employee_remarks', 'attachment',
        ]);
        $this->requested_leave_status = 'paid';
        $this->start_date = $this->planner_start;
        $this->end_date = $this->planner_end;
        $this->resetErrorBag();
        $this->resetValidation();
        $this->showRequestModal = true;
    }

    /**
     * Apply leave straight from a calendar day: opens the Apply modal with the
     * clicked date pre-filled as a single-day request (same fields/validation).
     */
    public function applyOnDate(string $date): void
    {
        $this->reset([
            'leave_type_id', 'is_half_day', 'half_day_period',
            'requested_leave_status', 'reason', 'employee_remarks', 'attachment',
        ]);
        $this->requested_leave_status = 'paid';
        $this->start_date = $date;
        $this->end_date = $date;
        $this->resetErrorBag();
        $this->resetValidation();
        $this->showRequestModal = true;
    }

    public function closeRequestModal(): void
    {
        $this->showRequestModal = false;
        $this->resetErrorBag();
        $this->resetValidation();
    }

    // ── Holiday Work ("Request to Work on Holiday") ──────────────────────────
    public function openHolidayWork(?string $date = null): void
    {
        $this->reset(['hw_date', 'hw_reason', 'hw_location', 'hw_hours', 'hw_project', 'hw_comments', 'hw_pay_type']);
        $this->hw_location = 'office';
        $this->hw_hours = 8;
        $this->hw_pay_type = HolidayPaySetting::current()->default_pay_type;
        $this->hw_date = $date ?? '';
        $this->resetValidation();
        $this->showHolidayWork = true;
    }

    public function submitHolidayWork(HolidayWorkService $service): void
    {
        $this->validate([
            'hw_date' => 'required|date',
            'hw_reason' => 'required|string|min:5',
            'hw_location' => 'required|in:office,wfh,client_site',
            'hw_hours' => 'required|numeric|min:0.5|max:24',
            'hw_pay_type' => 'required|in:overtime,comp_off,double_pay,extra_leave,half_day',
        ]);

        $employee = Auth::user()->employee;
        if (! $employee) {
            \Flux::toast('You do not have an active employee profile. Contact HR.', variant: 'danger');

            return;
        }

        try {
            $service->submit($employee, [
                'work_date' => $this->hw_date,
                'reason' => $this->hw_reason,
                'work_location' => $this->hw_location,
                'expected_hours' => $this->hw_hours,
                'project' => $this->hw_project ?: null,
                'comments' => $this->hw_comments ?: null,
                'pay_type' => $this->hw_pay_type,
            ]);
        } catch (\DomainException $e) {
            $this->addError('hw_date', $e->getMessage());

            return;
        }

        $this->showHolidayWork = false;
        \Flux::toast('Holiday-work request sent to your manager & HR for approval.', variant: 'success');
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

        // Store the single supporting document (≤5 MB).
        $attachmentsPayload = [];
        if ($this->attachment) {
            $attachmentsPayload[] = [
                'type' => 'supporting_document',
                'path' => $this->attachment->store('leave-attachments', 'public'),
                'original_name' => $this->attachment->getClientOriginalName(),
                'mime_type' => $this->attachment->getMimeType(),
                'size' => $this->attachment->getSize(),
            ];
        }

        if ($leaveType->attachment_required && $attachmentsPayload === []) {
            $this->addError('attachment', "An attachment is required for '{$leaveType->name}'.");

            return;
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
                null,
                $this->employee_remarks ?: null,
                $attachmentsPayload,
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
    // Conversation thread (reply to a reviewer's clarification request)
    // ================================================================

    public function openConversation(int $id): void
    {
        $employee = Auth::user()->employee;
        if (! $employee?->leaveRequests()->whereKey($id)->exists()) {
            return;
        }

        $this->conversationId = $id;
        $this->conversation_body = '';
        $this->conversation_attachment = null;
        $this->resetErrorBag();
    }

    public function closeConversation(): void
    {
        $this->conversationId = null;
        $this->conversation_attachment = null;
        $this->resetErrorBag();
    }

    public function postConversationMessage(LeaveService $service): void
    {
        $this->validate([
            'conversation_body' => 'nullable|string|max:2000',
            'conversation_attachment' => 'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png,webp',
        ]);

        $employee = Auth::user()->employee;
        $request = $employee?->leaveRequests()->find($this->conversationId);
        if (! $request) {
            $this->closeConversation();

            return;
        }

        $path = null;
        $name = null;
        if ($this->conversation_attachment) {
            $path = $this->conversation_attachment->store('leave-attachments', 'public');
            $name = $this->conversation_attachment->getClientOriginalName();
        }

        try {
            $service->postMessage($request, Auth::user(), $this->conversation_body ?: null, $path, $name);
        } catch (\DomainException $e) {
            $this->addError('conversation_body', $e->getMessage());

            return;
        }

        $this->conversation_body = '';
        $this->conversation_attachment = null;
        \Flux::toast('Message sent.', variant: 'success');
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
            ? $employee->leaveRequests()->with(['leaveType', 'reviewer', 'hrReviewer', 'paymentAuditLogs.changedByUser', 'attachments'])->withCount('messages')
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

        // Maternity resolves on its own terms. It used to be matched under an
        // 'mdl' alias, which made the employee's "MDL" card show maternity
        // while the policy text beside it described the December shutdown.
        // MDL means Mandatory December Leave and is not a leave balance at
        // all — it lives in december_mandatory_days and is surfaced separately.
        $maternity = $findBalance(['maternity']);

        $firstBalances = $balances->values();
        if (! $csl && $firstBalances->count() > 0) {
            $csl = $firstBalances->get(0);
        }
        if (! $compOff && $firstBalances->count() > 1) {
            $compOff = $firstBalances->get(1) ?? $firstBalances->get(0);
        }

        // No positional fallback here. Reaching for "whatever happens to be
        // third" showed an unrelated leave type under someone else's label —
        // if there is no maternity balance, there is no maternity balance.

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

        $holidays = PublicHoliday::query()
            ->active()
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->when($employee, fn ($q) => $q->forEmployee($employee))
            ->get()
            ->when($employee, fn ($c) => $c->filter->appliesToEmployee($employee))
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

        // ── Live holiday warning for the currently selected leave range ──
        $rangeHolidays = collect();
        if ($employee && $this->start_date && $this->end_date) {
            try {
                $rs = Carbon::parse($this->start_date);
                $re = Carbon::parse($this->end_date);
                if ($rs->lte($re)) {
                    $rangeHolidays = PublicHoliday::query()
                        ->active()
                        ->whereBetween('date', [$rs->toDateString(), $re->toDateString()])
                        ->forEmployee($employee)
                        ->orderBy('date')
                        ->get()
                        ->filter->appliesToEmployee($employee)
                        ->values();
                }
            } catch (\Throwable) {
                // Invalid partial date input — no warning.
            }
        }

        // ── Live weekend warning: Sat/Sun are non-working days, not leave ──
        $rangeWeekendDays = collect();
        if ($this->start_date || $this->end_date) {
            foreach (['start_date' => $this->start_date, 'end_date' => $this->end_date] as $edge) {
                if (! $edge) {
                    continue;
                }
                try {
                    $d = Carbon::parse($edge);
                    if ($d->isWeekend()) {
                        $rangeWeekendDays->push($d->format('l, d M'));
                    }
                } catch (\Throwable) {
                    // Invalid partial date input — no warning.
                }
            }
            $rangeWeekendDays = $rangeWeekendDays->unique()->values();
        }

        // ── Live leave-day count for the selected range (respects half-day +
        // sandwich, including a weekend bridged from an adjacent request) ──
        $rangeDays = null;
        if ($this->start_date && $this->end_date) {
            try {
                $rs = Carbon::parse($this->start_date);
                $re = Carbon::parse($this->end_date);
                if ($rs->lte($re)) {
                    $svc = app(LeaveService::class);
                    // Preview the same cross-request bridge the submit will apply
                    // so the estimate matches what actually gets charged.
                    if (! $this->is_half_day && $employee && $selectedType) {
                        [$rs, $re] = $svc->resolveSandwichBridge($employee, $selectedType, $rs, $re);
                    }
                    $rangeDays = $this->is_half_day
                        ? 0.5
                        : $svc->calculateLeaveDays($rs, $re, (bool) ($selectedType?->is_sandwich_applicable), (int) ($selectedType?->sandwich_min_days ?? 0));
                }
            } catch (\Throwable) {
                // Invalid partial date input — no count.
            }
        }

        // ── Holiday planner: upcoming public holidays + December mandatory days ──
        $upcomingHolidays = PublicHoliday::whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->limit(8)
            ->get();
        $mandatoryDays = DecemberMandatoryDay::where('year', now()->year)
            ->orderBy('date')
            ->get();

        // Merge balances by leave-type name for the balance cards. Duplicate
        // leave types in the data would otherwise render as separate cards
        // (e.g. two "Casual Leave"). Display-only — the raw $balances collection
        // is left untouched for the stat/forecast logic above.
        $balanceCards = $balances
            ->groupBy(fn ($b) => strtolower(trim($b->leaveType->name ?? 'other')))
            ->map(function ($group) {
                $first = $group->first();
                $allocated = (float) $group->sum('allocated_days');
                $used = (float) $group->sum('used_days');
                $encashed = (float) $group->sum(fn ($b) => (float) ($b->encashed_days ?? 0));

                return (object) [
                    'name' => $first->leaveType->name ?? 'Other',
                    'color' => $first->leaveType->color,
                    'allocated' => $allocated,
                    'used' => $used,
                    'encashed' => $encashed,
                    'carried' => (float) $group->sum(fn ($b) => (float) ($b->carried_forward_days ?? 0)),
                    'comp_off' => (float) $group->sum(fn ($b) => (float) ($b->comp_off_credits ?? 0)),
                    'available' => max(0, $allocated - $used - $encashed),
                ];
            })
            // Hide leave types with no activity at all — an all-zero card is noise.
            ->filter(fn ($c) => $c->allocated > 0 || $c->used > 0 || $c->available > 0
                || $c->carried > 0 || $c->comp_off > 0 || $c->encashed > 0)
            ->sortByDesc('allocated')
            ->values();

        // ── Leave Planner: a quick pre-apply breakdown (reuses the same
        // weekend/holiday rules as the Apply flow; no new business logic). ──
        $availableTotal = (float) $balanceCards->sum('available');
        $plannerResult = null;
        if ($employee && $this->planner_start && $this->planner_end) {
            try {
                $ps = Carbon::parse($this->planner_start);
                $pe = Carbon::parse($this->planner_end);
                if ($ps->lte($pe)) {
                    $total = $ps->diffInDays($pe) + 1;
                    $weekend = 0;
                    $cursor = $ps->copy();
                    while ($cursor->lte($pe)) {
                        if ($cursor->isWeekend()) {
                            $weekend++;
                        }
                        $cursor->addDay();
                    }
                    $holCount = PublicHoliday::query()->active()
                        ->whereBetween('date', [$ps->toDateString(), $pe->toDateString()])
                        ->forEmployee($employee)->get()
                        ->filter->appliesToEmployee($employee)
                        ->filter(fn ($h) => ! $h->date->isWeekend())
                        ->count();
                    $leaveDays = max(0, $total - $weekend - $holCount);
                    $plannerResult = [
                        'total' => $total,
                        'weekend' => $weekend,
                        'holidays' => $holCount,
                        'leaveDays' => $leaveDays,
                        'remaining' => max(0, $availableTotal - $leaveDays),
                    ];
                }
            } catch (\Throwable) {
                // Invalid partial date input — no breakdown.
            }
        }

        // ── Team Availability today (aggregated from existing leave/WFH data) ──
        $todayStr = now()->toDateString();
        $leaveTodayRows = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $todayStr)
            ->whereDate('end_date', '>=', $todayStr)
            ->get(['employee_id', 'is_half_day']);
        $fullLeaveIds = $leaveTodayRows->where('is_half_day', false)->pluck('employee_id')->flip();
        $halfDayIds = $leaveTodayRows->where('is_half_day', true)->pluck('employee_id')->flip();
        $wfhIds = WfhRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $todayStr)
            ->whereDate('end_date', '>=', $todayStr)
            ->pluck('employee_id')->flip();
        $teamAvailability = Employee::where('status', 'active')->with('department')->get()
            ->groupBy(fn ($e) => $e->department->name ?? 'Unassigned')
            ->map(function ($emps, $dept) use ($fullLeaveIds, $halfDayIds, $wfhIds) {
                $onLeave = $emps->filter(fn ($e) => $fullLeaveIds->has($e->id))->count();

                return (object) [
                    'dept' => $dept,
                    'total' => $emps->count(),
                    'on_leave' => $onLeave,
                    'half_day' => $emps->filter(fn ($e) => $halfDayIds->has($e->id))->count(),
                    'wfh' => $emps->filter(fn ($e) => $wfhIds->has($e->id) && ! $fullLeaveIds->has($e->id))->count(),
                    'present' => max(0, $emps->count() - $onLeave),
                ];
            })
            ->sortByDesc('total')
            ->values();

        // Hero KPI: total approved leave days taken this calendar year.
        $approvedThisYearDays = $employee
            ? (float) $employee->leaveRequests()
                ->where('status', 'approved')
                ->whereYear('start_date', now()->year)
                ->sum('days')
            : 0.0;

        return view('livewire.time-off.my-time-off', [
            'balances' => $balances,
            'balanceCards' => $balanceCards,
            'approvedThisYearDays' => $approvedThisYearDays,
            'availableTotal' => $availableTotal,
            'plannerResult' => $plannerResult,
            'teamAvailability' => $teamAvailability,
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
                // Maternity, named as maternity. There is deliberately no
                // 'mdl' key: Mandatory December Leave is not a balance, and
                // $mandatoryDays below carries it.
                'maternity' => $maternity,
            ],
            'selectedType' => $selectedType,
            'selectedBalance' => $selectedBalance,
            'calendarDays' => $calendarDays,
            'calendarLabel' => $monthStart->format('F Y'),
            'forecast' => $forecast,
            'upcomingHolidays' => $upcomingHolidays,
            'mandatoryDays' => $mandatoryDays,
            'rangeHolidays' => $rangeHolidays,
            'rangeWeekendDays' => $rangeWeekendDays,
            'rangeDays' => $rangeDays,
            'holidayPaySettings' => HolidayPaySetting::current(),
            'holidayWorkedCount' => $employee
                ? HolidayWorkRequest::where('employee_id', $employee->id)
                    ->where('status', 'approved')
                    ->whereYear('work_date', now()->year)
                    ->count()
                : 0,
            'conversationRequest' => ($employee && $this->conversationId)
                ? $employee->leaveRequests()->with(['leaveType', 'messages.user'])->find($this->conversationId)
                : null,
        ])->layout('layouts.app', ['title' => 'My Time Off']);
    }
}
