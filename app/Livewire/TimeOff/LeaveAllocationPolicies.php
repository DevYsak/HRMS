<?php

namespace App\Livewire\TimeOff;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\EmploymentType;
use App\Models\JobTitle;
use App\Models\LeaveAllocationPolicy;
use App\Models\LeaveType;
use Livewire\Component;

/**
 * Manage conditional default-leave rules (Phase 2 — Feature 4 full). Each rule
 * sets a leave type's default allocation for employees matching optional
 * conditions; the most specific match wins on hire.
 */
class LeaveAllocationPolicies extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $leave_type_id = '';

    public string $department_id = '';

    public string $job_title_id = '';

    public string $employment_type_id = '';

    public string $gender = '';

    public string $role = '';

    public int $min_service_months = 0;

    public bool $requires_probation_complete = false;

    public string $allocated_days = '';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'leave_type_id', 'department_id', 'job_title_id', 'employment_type_id', 'gender', 'role', 'min_service_months', 'requires_probation_complete', 'allocated_days');
        $this->is_active = true;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $p = LeaveAllocationPolicy::findOrFail($id);
        $this->editingId = $p->id;
        $this->leave_type_id = (string) $p->leave_type_id;
        $this->department_id = (string) ($p->department_id ?? '');
        $this->job_title_id = (string) ($p->job_title_id ?? '');
        $this->employment_type_id = (string) ($p->employment_type_id ?? '');
        $this->gender = (string) ($p->gender ?? '');
        $this->role = (string) ($p->role ?? '');
        $this->min_service_months = (int) $p->min_service_months;
        $this->requires_probation_complete = (bool) $p->requires_probation_complete;
        $this->allocated_days = (string) $p->allocated_days;
        $this->is_active = (bool) $p->is_active;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        $this->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'allocated_days' => ['required', 'numeric', 'min:0'],
            'min_service_months' => ['integer', 'min:0'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_title_id' => ['nullable', 'exists:job_titles,id'],
            'employment_type_id' => ['nullable', 'exists:employment_types,id'],
        ]);

        LeaveAllocationPolicy::updateOrCreate(
            ['id' => $this->editingId],
            [
                'leave_type_id' => (int) $this->leave_type_id,
                'department_id' => $this->department_id !== '' ? (int) $this->department_id : null,
                'job_title_id' => $this->job_title_id !== '' ? (int) $this->job_title_id : null,
                'employment_type_id' => $this->employment_type_id !== '' ? (int) $this->employment_type_id : null,
                'gender' => $this->gender ?: null,
                'role' => $this->role ?: null,
                'min_service_months' => $this->min_service_months,
                'requires_probation_complete' => $this->requires_probation_complete,
                'allocated_days' => (float) $this->allocated_days,
                'is_active' => $this->is_active,
            ],
        );

        $this->showModal = false;
        \Flux::toast('Leave policy saved.', variant: 'success');
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');
        LeaveAllocationPolicy::whereKey($id)->delete();
        \Flux::toast('Leave policy removed.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.time-off.leave-allocation-policies', [
            'policies' => LeaveAllocationPolicy::with(['leaveType', 'department', 'jobTitle', 'employmentType'])
                ->orderByDesc('id')->get(),
            'leaveTypes' => LeaveType::whereNull('deleted_at')->orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
            'jobTitles' => JobTitle::orderBy('name')->get(),
            'employmentTypes' => EmploymentType::orderBy('name')->get(),
            'roles' => UserRole::cases(),
        ])->layout('layouts.app', ['title' => 'Leave Policies']);
    }
}
