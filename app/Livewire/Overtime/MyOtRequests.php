<?php

namespace App\Livewire\Overtime;

use App\Models\OtRequest;
use App\Models\User;
use App\Notifications\OtRequestNotification;
use App\Services\Notifications\NotificationRecipients;
use App\Services\OvertimeService;
use App\Services\Teams\ApprovalRoutingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class MyOtRequests extends Component
{
    use WithPagination;

    public bool $showModal = false;

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

        // Notify the reporting manager plus the team lead/backup chain (v4
        // Part 3.2) for the work date, deduped by user id. Fall back to
        // HR/SuperAdmin/manager only when nobody resolves.
        $recipients = collect();
        $push = function (?User $u) use ($recipients) {
            if ($u && ! $recipients->contains(fn (User $r) => $r->id === $u->id)) {
                $recipients->push($u);
            }
        };

        $push($employee->manager);
        app(ApprovalRoutingService::class)
            ->getApproverChain($employee, Carbon::parse($this->work_date))
            ->each($push);

        if ($recipients->isEmpty()) {
            // Neither a manager nor an approver chain resolved, so this falls
            // to HR. It previously also went to every manager in the company,
            // who have no relationship to this employee's request.
            $recipients = app(NotificationRecipients::class)->hrQueue();
        }

        // Approver chain or manager; HR only when neither resolved.
        $role = $employee->manager && $recipients->contains('id', $employee->manager->id) ? 'approver' : 'hr_admin';
        $recipients->each(fn (User $u) => $u->notify(
            (new OtRequestNotification($request))->forRole($u->id === $employee->manager?->id ? 'manager' : $role)
        ));

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
        $employee = Auth::user()->employee;

        $query = $employee
            ? $employee->otRequests()->with('reviewer')
            : OtRequest::whereNull('id');

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterMonth) {
            [$y, $m] = explode('-', $this->filterMonth);
            $query->whereYear('work_date', $y)->whereMonth('work_date', $m);
        }

        $requests = $query->latest('work_date')->paginate(10);

        $summary = $employee ? [
            'total' => $employee->otRequests()->count(),
            'approved' => $employee->otRequests()->where('status', 'approved')->count(),
            'pending' => $employee->otRequests()->where('status', 'pending')->count(),
            'hours' => (float) $employee->otRequests()->where('status', 'approved')->sum('requested_hours'),
        ] : ['total' => 0, 'approved' => 0, 'pending' => 0, 'hours' => 0];

        return view('livewire.overtime.my-ot-requests', [
            'requests' => $requests,
            'summary' => $summary,
        ])->layout('layouts.app', ['title' => 'My OT Requests']);
    }
}
