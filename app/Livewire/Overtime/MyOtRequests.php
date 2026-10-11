<?php

namespace App\Livewire\Overtime;

use App\Models\OtRequest;
use App\Services\Ai\AiDataTools;
use App\Services\OvertimeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class MyOtRequests extends Component
{
    use WithPagination;

    public bool $showModal = false;

    /** The request whose details and timeline are open, if any. */
    public ?int $viewingId = null;

    // Form fields
    public string $work_date = '';

    public string $start_time = '';

    public string $end_time = '';

    public string $reason = '';

    protected function rules(): array
    {
        return [
            'work_date' => 'required|date|before_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'reason' => 'required|min:5|max:500',
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'end_time.after' => 'The end time must be after the start time.',
            'work_date.before_or_equal' => 'Overtime can only be requested for today or an earlier date.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'work_date' => 'work date',
            'start_time' => 'start time',
            'end_time' => 'end time',
            'reason' => 'reason',
        ];
    }

    public function showDetails(int $id): void
    {
        // Own requests only — someone else's id simply is not found.
        $this->viewingId = OtRequest::where('employee_id', Auth::user()->employee?->id)->findOrFail($id)->id;
    }

    public function closeDetails(): void
    {
        $this->viewingId = null;
    }

    public function openModal(): void
    {
        $this->reset(['work_date', 'start_time', 'end_time', 'reason']);
        $this->showModal = true;
    }

    public function submit(OvertimeService $service): void
    {
        $this->validate();

        $employee = Auth::user()->employee;

        if (! $employee) {
            \Flux::toast('No employee profile found. Contact HR.', variant: 'danger');
            $this->showModal = false;

            return;
        }

        // Prevent duplicate pending request for same date
        $exists = OtRequest::where('employee_id', $employee->id)
            ->where('work_date', $this->work_date)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($exists) {
            $this->addError('work_date', 'An OT request already exists for this date.');

            return;
        }

        try {
            $request = $service->submitRequest($employee, [
                'work_date' => $this->work_date,
                'start_time' => $this->start_time,
                'end_time' => $this->end_time,
                'reason' => $this->reason,
            ]);
        } catch (\DomainException $e) {
            $this->addError('work_date', $e->getMessage());

            return;
        }

        // The reporting manager plus the team lead/backup chain for the work
        // date (v4 Part 3.2); HR only when nobody resolves.
        app(OvertimeService::class)->notifyApprovers($request);

        $this->showModal = false;
        \Flux::toast('OT request submitted successfully.');
        $this->resetPage();
    }

    public function cancel(int $id): void
    {
        $employee = Auth::user()->employee;
        $request = OtRequest::where('employee_id', $employee?->id)->findOrFail($id);

        if (! $request->isPending()) {
            \Flux::toast('Only pending requests can be cancelled.', variant: 'warning');

            return;
        }

        $request->update(['status' => 'cancelled']);
        $this->viewingId = null;
        \Flux::toast('OT request cancelled.');
        $this->resetPage();
    }

    public string $filterStatus = '';

    public string $filterMonth = '';

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFilterMonth(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->filterStatus = '';
        $this->filterMonth = '';
        $this->resetPage();
    }

    public function render()
    {
        $user = Auth::user();
        $employee = $user->employee;

        $query = $employee
            ? $employee->otRequests()->with(['reviewer', 'overtimeRecord'])
            : OtRequest::whereNull('id');

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterMonth && preg_match('/^\d{4}-\d{2}$/', $this->filterMonth)) {
            [$y, $m] = explode('-', $this->filterMonth);
            $query->whereYear('work_date', $y)->whereMonth('work_date', $m);
        }

        $requests = $query->latest('work_date')->latest('id')->paginate(10);

        // One canonical summary (OvertimeService) — the same numbers the
        // dashboard and the AI assistant report.
        $summary = $employee
            ? app(OvertimeService::class)->summaryFor($employee)
            : ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'total' => 0, 'approved_hours_month' => 0.0, 'approved_amount_month' => 0.0];

        $viewing = $this->viewingId && $employee
            ? $employee->otRequests()->with(['reviewer', 'overtimeRecord'])->find($this->viewingId)
            : null;

        return view('livewire.overtime.my-ot-requests', [
            'requests' => $requests,
            'summary' => $summary,
            'showPay' => app(AiDataTools::class)->maySeeOwnPay($user),
            'viewing' => $viewing,
        ])->layout('layouts.app', ['title' => 'My Overtime']);
    }
}
