<?php

namespace App\Livewire\Wfh;

use App\Models\User;
use App\Models\WfhRequest;
use App\Notifications\WfhRequestNotification;
use App\Services\WfhService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class MyWfhRequests extends Component
{
    use WithPagination;

    public bool $showModal = false;

    // Form fields
    public string $start_date = '';

    public string $end_date = '';

    public bool $is_half_day = false;

    public string $half_day_period = '';

    public string $reason = '';

    public string $filterStatus = '';

    public string $filterMonth = '';

    protected function rules(): array
    {
        return [
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_half_day' => 'boolean',
            'half_day_period' => 'required_if:is_half_day,true|nullable|in:first_half,second_half',
            'reason' => 'required|min:5|max:500',
        ];
    }

    public function updatedIsHalfDay(bool $value): void
    {
        if (! $value) {
            $this->half_day_period = '';
        }
    }

    public function openModal(): void
    {
        $this->reset(['start_date', 'end_date', 'is_half_day', 'half_day_period', 'reason']);
        $this->resetErrorBag();
        $this->resetValidation();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function submit(WfhService $service): void
    {
        $this->validate();

        $employee = Auth::user()->employee;

        if (! $employee) {
            \Flux::toast('No employee profile found. Contact HR.', variant: 'danger');
            $this->closeModal();

            return;
        }

        try {
            $request = $service->submitRequest($employee, [
                'start_date' => $this->start_date,
                'end_date' => $this->end_date,
                'is_half_day' => $this->is_half_day,
                'half_day_period' => $this->half_day_period ?: null,
                'reason' => $this->reason,
            ]);
        } catch (\DomainException $exception) {
            $this->addError('start_date', $exception->getMessage());

            return;
        }

        // Notify manager; fallback to HR/SuperAdmin if no manager assigned
        $manager = $employee->manager;
        if ($manager) {
            $manager->notify(new WfhRequestNotification($request));
        } else {
            User::whereIn('role', ['hr_admin', 'super_admin', 'manager'])
                ->each(fn ($u) => $u->notify(new WfhRequestNotification($request)));
        }

        $this->closeModal();
        \Flux::toast('Work-from-home request submitted successfully.');
        $this->resetPage();
    }

    public function cancel(int $id, WfhService $service): void
    {
        $employee = Auth::user()->employee;
        $request = WfhRequest::where('employee_id', $employee?->id)->findOrFail($id);

        try {
            $service->cancel($request);
            \Flux::toast('Work-from-home request cancelled.');
            $this->resetPage();
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'warning');
        }
    }

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
            ? $employee->wfhRequests()->with('reviewer')
            : WfhRequest::whereNull('id');

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterMonth) {
            [$y, $m] = explode('-', $this->filterMonth);
            $query->whereYear('start_date', $y)->whereMonth('start_date', $m);
        }

        $requests = $query->latest('start_date')->paginate(10);

        $summary = $employee ? [
            'total' => $employee->wfhRequests()->count(),
            'approved' => $employee->wfhRequests()->where('status', 'approved')->count(),
            'pending' => $employee->wfhRequests()->where('status', 'pending')->count(),
        ] : ['total' => 0, 'approved' => 0, 'pending' => 0];

        return view('livewire.wfh.my-wfh-requests', [
            'requests' => $requests,
            'summary' => $summary,
        ])->layout('layouts.app', ['title' => 'My Work From Home']);
    }
}
