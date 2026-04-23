<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
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

    public string $reason = '';

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
        $this->reset(['leave_type_id', 'start_date', 'end_date', 'reason']);
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

        $start = Carbon::parse($this->start_date);
        $end = Carbon::parse($this->end_date);
        $days = $start->diffInDays($end) + 1;

        $leaveType = LeaveType::find($this->leave_type_id);
        if ($leaveType?->is_paid) {
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $this->leave_type_id)
                ->first();

            if (! $balance || ($balance->allocated_days - $balance->used_days) < $days) {
                $this->addError('leave_type_id', 'Insufficient balance for this request.');

                return;
            }
        }

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->leave_type_id,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'days' => $days,
            'reason' => $this->reason,
            'status' => 'pending',
        ]);

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

        LeaveEncashment::create([
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
            ? $employee->leaveBalances()->with('leaveType')->get()
            : collect();

        $requests = $employee
            ? $employee->leaveRequests()->with(['leaveType', 'reviewer'])->latest()->paginate(10)
            : collect();

        // Encashable leave types (current employee has balance & type allows encashment)
        $encashableTypes = $employee
            ? LeaveType::where('allow_encashment', true)
                ->whereHas('leaveBalances', fn ($q) => $q->where('employee_id', $employee->id))
                ->get()
            : collect();

        $encashments = $employee
            ? LeaveEncashment::where('employee_id', $employee->id)->with('leaveType')->latest()->get()
            : collect();

        return view('livewire.time-off.my-time-off', [
            'balances' => $balances,
            'requests' => $requests,
            'leaveTypes' => LeaveType::all(),
            'encashableTypes' => $encashableTypes,
            'encashments' => $encashments,
        ])->layout('layouts.app', ['title' => 'My Time Off']);
    }
}
