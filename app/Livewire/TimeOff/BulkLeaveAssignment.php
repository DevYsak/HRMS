<?php

namespace App\Livewire\TimeOff;

use App\Enums\EmployeeStatus;
use App\Models\Department;
use App\Models\EmploymentType;
use App\Models\JobTitle;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\ShiftSetting;
use App\Services\BulkLeaveService;
use Livewire\Component;

/**
 * Bulk assign / increase / decrease / reset leave balances across a filtered
 * set of employees, with a dry-run preview. (Phase 2 — Feature 6.)
 */
class BulkLeaveAssignment extends Component
{
    // ── Filters ─────────────────────────────────────────────────────────────
    public ?int $department_id = null;

    public ?int $job_title_id = null;

    public ?int $shift_id = null;

    public ?int $office_id = null;

    public ?int $employment_type_id = null;

    public ?string $status = null;

    public ?string $joined_from = null;

    public ?string $joined_to = null;

    // ── Operation ───────────────────────────────────────────────────────────
    public ?int $leave_type_id = null;

    public string $action = 'assign';

    public ?float $days = null;

    public string $reason = '';

    public int $year;

    // ── Preview state ───────────────────────────────────────────────────────
    public bool $showPreview = false;

    public function mount(): void
    {
        $this->authorize('manage-settings');
        $this->year = now()->year;
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'department_id' => $this->department_id,
            'job_title_id' => $this->job_title_id,
            'shift_id' => $this->shift_id,
            'office_id' => $this->office_id,
            'employment_type_id' => $this->employment_type_id,
            'status' => $this->status,
            'joined_from' => $this->joined_from,
            'joined_to' => $this->joined_to,
        ];
    }

    private function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'action' => ['required', 'in:'.implode(',', BulkLeaveService::ACTIONS)],
            'days' => [$this->action === 'reset' ? 'nullable' : 'required', 'numeric', 'min:0.5'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function preview(BulkLeaveService $service): void
    {
        $this->authorize('manage-settings');
        $this->validate($this->rules());

        $this->showPreview = true;
    }

    public function apply(BulkLeaveService $service): void
    {
        $this->authorize('manage-settings');
        $this->validate($this->rules());

        $employees = $service->affectedEmployees($this->filters());

        if ($employees->isEmpty()) {
            \Flux::toast('No employees match the selected filters.', variant: 'warning');

            return;
        }

        try {
            $leaveType = LeaveType::findOrFail($this->leave_type_id);
            $count = $service->apply(
                $employees,
                $leaveType,
                $this->action,
                (float) ($this->days ?? 0),
                $this->reason,
                auth()->user(),
                $this->year,
            );

            $this->showPreview = false;
            \Flux::toast("Leave updated for {$count} employee(s).", variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    public function render()
    {
        $service = app(BulkLeaveService::class);
        $employees = $service->affectedEmployees($this->filters());

        $previewRows = [];
        if ($this->showPreview && $this->leave_type_id) {
            $leaveType = LeaveType::find($this->leave_type_id);
            if ($leaveType) {
                $previewRows = $service->preview($employees, $leaveType, $this->action, (float) ($this->days ?? 0), $this->year);
            }
        }

        return view('livewire.time-off.bulk-leave-assignment', [
            'matchCount' => $employees->count(),
            'previewRows' => $previewRows,
            'departments' => Department::orderBy('name')->get(),
            'jobTitles' => JobTitle::orderBy('name')->get(),
            'shifts' => ShiftSetting::orderBy('name')->get(),
            'offices' => Office::orderBy('name')->get(),
            'employmentTypes' => EmploymentType::orderBy('name')->get(),
            'leaveTypes' => LeaveType::whereNull('deleted_at')->orderBy('name')->get(),
            'statuses' => EmployeeStatus::cases(),
        ])->layout('layouts.app', ['title' => 'Bulk Leave Assignment']);
    }
}
