<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Services\Approvals\ApprovalGuard;
use App\Services\ProbationEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class ProbationConfirmation extends Component
{
    public Employee $employee;

    public string $managerComment = '';

    public string $hrComment = '';

    public string $extendEndDate = '';

    public string $extendReason = '';

    public bool $showExtendForm = false;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;
        abort_unless(
            Auth::user()->hasPermission('view_probation') && Auth::user()->coversEmployee($employee, 'view_probation'),
            403
        );
    }

    /** Step 1 — Manager confirms probation completion. */
    public function managerConfirm(): void
    {
        $this->validate(['managerComment' => 'nullable|string|max:500']);

        try {
            app(ProbationEngine::class)->managerConfirm(
                $this->employee,
                Auth::user(),
                $this->managerComment ?: null,
            );

            $this->employee = $this->employee->fresh();
            $this->managerComment = '';
            \Flux::toast('Probation confirmed. Awaiting HR approval.', variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    /** Step 2 — HR Admin co-approves and moves employee to Confirmed status. */
    public function hrApprove(): void
    {
        $this->validate(['hrComment' => 'nullable|string|max:500']);

        try {
            app(ProbationEngine::class)->hrApprove(
                $this->employee,
                Auth::user(),
                $this->hrComment ?: null,
            );

            $this->employee = $this->employee->fresh();
            $this->hrComment = '';
            \Flux::toast('Probation fully confirmed. Employee is now Confirmed.', variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    /** Extend probation period. */
    public function extendProbation(): void
    {
        // Same rule as the employee record's extend: manage employees, within scope, never one's own.
        $this->authorize('update', $this->employee);
        app(ApprovalGuard::class)->assertNotSelf(Auth::user(), $this->employee);

        $this->validate([
            'extendEndDate' => 'required|date|after:today',
            'extendReason' => 'required|string|max:1000',
        ]);

        try {
            app(ProbationEngine::class)->extend(
                $this->employee,
                Auth::user(),
                $this->extendEndDate,
                $this->extendReason,
            );

            $this->employee = $this->employee->fresh();
            $this->extendEndDate = '';
            $this->extendReason = '';
            $this->showExtendForm = false;
            \Flux::toast('Probation extended and manager notified.', variant: 'success');
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $engine = app(ProbationEngine::class);

        return view('livewire.employees.probation-confirmation', [
            'canConfirm' => Auth::user()->hasPermission('confirm_probation'),
            'canHrApprove' => Auth::user()->hasPermission('hr_approve_probation'),
            'requiresManagerReview' => $engine->requiresManagerReview($this->employee),
            'requiresHrApproval' => $engine->requiresHrApproval($this->employee),
            'probationDays' => $engine->resolveProbationDays($this->employee),
        ]);
    }
}
