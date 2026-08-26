<?php

namespace App\Livewire\Employees;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Office;
use App\Services\Biometric\BiometricCodeService;
use Livewire\Component;
use Livewire\WithPagination;

class EmployeeIndex extends Component
{
    use WithPagination;

    public $search = '';

    public $office_id = '';

    public $department_id = '';

    public $job_title_id = '';

    public $status = '';

    /**
     * Show deleted employees instead of active ones.
     *
     * Deleting soft-deletes both records and leaves every leave balance,
     * attendance row, payslip and audit entry in place — but nothing in the
     * application could see them afterwards, let alone bring them back. A
     * deleted employee was unreachable and their email permanently spent.
     */
    public bool $showDeleted = false;

    public function mount()
    {
        $this->authorize('viewAny', Employee::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingOfficeId(): void
    {
        $this->resetPage();
    }

    public function updatingDepartmentId(): void
    {
        $this->resetPage();
    }

    public function updatingJobTitleId(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingShowDeleted(): void
    {
        $this->resetPage();
    }

    /**
     * Bring a deleted employee back, with their history.
     *
     * The biometric code was released on deletion and is deliberately not
     * reclaimed here: it may already belong to somebody else, and silently
     * taking it back would point their punches at the wrong person. HR
     * reassigns it explicitly.
     */
    public function restoreEmployee($id): void
    {
        $employee = Employee::withTrashed()->findOrFail($id);
        $this->authorize('delete', $employee);

        if (! $employee->trashed()) {
            return;
        }

        $employee->restore();
        $employee->user()->withTrashed()->first()?->restore();

        AuditLog::record($employee, 'restored', null, ['restored_by' => 'employee index']);

        \Flux::toast($employee->employee_code
            ? 'Employee restored with their history.'
            : 'Employee restored with their history. Their Biometric Device ID was released on deletion — reassign it from the employee record.');
    }

    /**
     * Erase a deleted employee for good.
     *
     * Irreversible, and it takes the person's leave, attendance, payslips and
     * audit trail with it. Only reachable for an already-deleted record, so
     * removing somebody is always two deliberate steps rather than one click.
     */
    public function forceDeleteEmployee($id): void
    {
        $employee = Employee::withTrashed()->findOrFail($id);
        $this->authorize('forceDelete', $employee);

        if (! $employee->trashed()) {
            \Flux::toast('Delete the employee first — permanent removal only applies to an already-deleted record.', variant: 'danger');

            return;
        }

        $name = $employee->user()->withTrashed()->first()?->name ?? $employee->employee_id;

        // Recorded before the row goes, or there would be nothing to attach it
        // to afterwards.
        AuditLog::record($employee, 'force_deleted', $employee->toArray(), null);

        $employee->user()->withTrashed()->first()?->forceDelete();
        $employee->forceDelete();

        \Flux::toast("{$name} permanently deleted. This cannot be undone.");
    }

    public function deleteEmployee($id)
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('delete', $employee);

        // Free the Biometric Device ID before soft-deleting. Without this the
        // trashed row keeps holding the device PIN, and the replacement hire
        // is told the ID is "already taken" by somebody who no longer appears
        // anywhere in the directory. Offboarding already does this via
        // BiometricSyncService::releaseEmployee(); a direct delete must too.
        $code = $employee->employee_code;
        app(BiometricCodeService::class)->release($employee, auth()->user());

        $employee->delete();
        $employee->user->delete();

        \Flux::toast($code
            ? "Employee deleted. Biometric Device ID {$code} is free to reassign."
            : 'Employee deleted successfully.');
    }

    public function render()
    {
        $user = auth()->user();

        $employees = Employee::with(['user' => fn ($q) => $q->withTrashed(), 'office', 'department', 'jobTitle', 'manager', 'shift'])
            ->when($this->showDeleted, fn ($q) => $q->onlyTrashed())
            ->when(! $user->canManageEmployees(), function ($query) use ($user) {
                $query->where('manager_id', $user->employee?->id);
            })
            ->when($this->search, function ($query) {
                $s = '%'.$this->search.'%';
                $query->where(function ($q) use ($s) {
                    $q->where('employee_id', 'like', $s)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $s)->orWhere('email', 'like', $s));
                });
            })
            ->when($this->office_id, fn ($q) => $q->where('office_id', $this->office_id))
            ->when($this->department_id, fn ($q) => $q->where('department_id', $this->department_id))
            ->when($this->job_title_id, fn ($q) => $q->where('job_title_id', $this->job_title_id))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.employees.employee-index', [
            'employees' => $employees,
            'offices' => Office::all(),
            'departments' => Department::orderBy('name')->get(),
            'jobTitles' => JobTitle::all(),
        ])->layout('layouts.app', ['title' => 'Manage Employees']);
    }
}
