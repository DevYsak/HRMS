<?php

namespace App\Livewire\Payroll;

use App\Models\Payroll;
use App\Models\PayrollApprovalStep;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class FinanceApproval extends Component
{
    public $pendingPayrolls;

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    /** Set instead of rejectingId when rejecting a single step of a configured chain. */
    public ?int $rejectingStepId = null;

    public function mount()
    {
        $this->loadPending();
    }

    /**
     * Reachable by anyone who can either run payroll (e.g. an hr_admin
     * acting on an "HR Review" step) or approve finance the legacy way —
     * the real per-action authorization happens per-step in the view/service.
     */
    private function authorizeAccess(): void
    {
        abort_unless(Auth::user()->canApproveFinance() || Auth::user()->canRunPayroll(), 403);
    }

    public function loadPending()
    {
        $this->pendingPayrolls = Payroll::where('status', 'pending_finance')
            ->with(['payslips.employee.user', 'processedBy', 'approvalSteps.approver', 'approvalSteps.specificUser'])
            ->latest()
            ->get();
    }

    /** Legacy single-hop approval — only valid for a payroll with no configured steps. */
    public function approve($payrollId, PayrollService $payrollService)
    {
        $this->authorizeAccess();

        $payroll = Payroll::findOrFail($payrollId);

        if ($payroll->status !== 'pending_finance') {
            return;
        }

        try {
            $payroll = $payrollService->approveFinance($payroll, Auth::id());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $payrollService->dispatchFinalizedPayrollNotifications($payroll);

        $this->loadPending();
        \Flux::toast('Payroll approved and finalized!');
    }

    /** Open the reject-reason modal for a specific payroll (legacy, no configured steps). */
    public function openReject(int $payrollId): void
    {
        $this->authorizeAccess();

        $this->rejectingId = $payrollId;
        $this->rejectingStepId = null;
        $this->rejectReason = '';
        $this->modal('reject-payroll')->show();
    }

    /** Confirm rejection with the reason entered in the shared modal — routes to whichever of rejectingId/rejectingStepId is set. */
    public function confirmReject(): void
    {
        $this->authorizeAccess();

        if ($this->rejectingStepId !== null) {
            $this->confirmRejectStep();

            return;
        }

        $payroll = Payroll::findOrFail($this->rejectingId);

        if ($payroll->status !== 'pending_finance') {
            \Flux::toast('Only pending payrolls can be rejected.', variant: 'danger');
            $this->modal('reject-payroll')->close();

            return;
        }

        try {
            app(PayrollService::class)->rejectFinance($payroll, trim($this->rejectReason) !== '' ? trim($this->rejectReason) : null);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
            $this->modal('reject-payroll')->close();

            return;
        }

        $this->modal('reject-payroll')->close();
        $this->rejectingId = null;
        $this->rejectReason = '';
        $this->loadPending();
        \Flux::toast('Payroll sent back for corrections.');
    }

    /** Approve the current step of a configured chain. */
    public function approveStep(int $stepId, PayrollService $payrollService)
    {
        $this->authorizeAccess();

        $step = PayrollApprovalStep::findOrFail($stepId);

        try {
            $payroll = $payrollService->approveStep($step, Auth::user());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        if ($payroll->status === 'finalized') {
            $payrollService->dispatchFinalizedPayrollNotifications($payroll);
            \Flux::toast('Final step approved — payroll finalized!');
        } else {
            \Flux::toast('Step approved — moved to the next approver.');
        }

        $this->loadPending();
    }

    /** Open the reject-reason modal for a specific step of a configured chain. */
    public function openRejectStep(int $stepId): void
    {
        $this->authorizeAccess();

        $this->rejectingStepId = $stepId;
        $this->rejectingId = null;
        $this->rejectReason = '';
        $this->modal('reject-payroll')->show();
    }

    /** Confirm rejection of a single step, entered in the shared modal. */
    public function confirmRejectStep(): void
    {
        $this->authorizeAccess();

        $step = PayrollApprovalStep::findOrFail($this->rejectingStepId);

        try {
            app(PayrollService::class)->rejectStep($step, Auth::user(), trim($this->rejectReason) !== '' ? trim($this->rejectReason) : null);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
            $this->modal('reject-payroll')->close();

            return;
        }

        $this->modal('reject-payroll')->close();
        $this->rejectingStepId = null;
        $this->rejectReason = '';
        $this->loadPending();
        \Flux::toast('Payroll sent back for corrections.');
    }

    public function render()
    {
        $this->authorizeAccess();

        return view('livewire.payroll.finance-approval')
            ->layout('layouts.app', ['title' => 'Finance Approval']);
    }
}
