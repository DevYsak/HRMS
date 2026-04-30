<?php

namespace App\Livewire\Overtime;

use App\Models\OtRequest;
use App\Models\User;
use App\Notifications\OtRequestNotification;
use App\Services\OvertimeService;
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

        $request = $service->submitRequest($employee, [
            'work_date' => $this->work_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'reason' => $this->reason,
        ]);

        // Notify manager; fallback to HR/SuperAdmin if no manager assigned
        $manager = $employee->manager;
        if ($manager) {
            $manager->notify(new OtRequestNotification($request));
        } else {
            User::whereIn('role', ['hr_admin', 'super_admin', 'manager'])
                ->each(fn ($u) => $u->notify(new OtRequestNotification($request)));
        }

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

    public function render()
    {
        $employee = Auth::user()->employee;

        $requests = $employee
            ? $employee->otRequests()->with('reviewer')->latest()->paginate(10)
            : collect();

        return view('livewire.overtime.my-ot-requests', [
            'requests' => $requests,
        ])->layout('layouts.app', ['title' => 'My OT Requests']);
    }
}
