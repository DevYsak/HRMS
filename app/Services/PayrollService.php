<?php

namespace App\Services;

use App\Mail\PayslipMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\PayrollApprovalPolicy;
use App\Models\PayrollApprovalStep;
use App\Models\PayrollRunFailure;
use App\Models\Payslip;
use App\Models\SalaryRevision;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Notifications\PayrollApprovalNotification;
use App\Notifications\PayslipGeneratedNotification;
use App\Notifications\SalaryStructureAssignedNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PayrollService
{
    public function __construct(
        protected OvertimeService $overtimeService,
        protected IncentiveService $incentiveService,
        protected ReimbursementService $reimbursementService,
        protected SalaryCalculationService $salaryCalculationService,
    ) {}

    public function generateDraft(string $month, int $year, string $cycle, int $processedBy): Payroll
    {
        try {
            return $this->runGenerateDraft($month, $year, $cycle, $processedBy);
        } catch (\Throwable $e) {
            // A bad run used to just vanish as a toast — record it so it shows up
            // somewhere (the payroll dashboard's "Failed" widget reads this table).
            PayrollRunFailure::create([
                'month' => $month,
                'year' => $year,
                'cycle' => $cycle,
                'attempted_by' => $processedBy,
                'reason' => $e->getMessage(),
                'context' => ['exception' => get_class($e)],
            ]);

            throw $e;
        }
    }

    private function runGenerateDraft(string $month, int $year, string $cycle, int $processedBy): Payroll
    {
        [$cycleFrom, $cycleTo] = $this->resolveCycleDates($month, $year, $cycle);
        $monthLabel = Carbon::parse("1 {$month} {$year}")->format('Y-m');

        return DB::transaction(function () use ($month, $year, $cycle, $processedBy, $cycleFrom, $cycleTo, $monthLabel) {
            $payroll = Payroll::where('month', $month)
                ->where('year', $year)
                ->where('cycle', $cycle)
                ->first();

            if ($payroll && $payroll->isLocked()) {
                throw new \DomainException('This payroll is locked and can no longer be regenerated.');
            }

            if ($payroll && $payroll->status !== 'draft') {
                throw new \DomainException('Payroll is locked for this cycle until finance completes review.');
            }

            if (! $payroll) {
                $payroll = Payroll::create([
                    'month' => $month,
                    'year' => $year,
                    'cycle' => $cycle,
                    'status' => 'draft',
                    'processed_by' => $processedBy,
                ]);
            }

            $employees = Employee::where('status', 'active')
                ->where('salary_cycle', $cycle)
                ->with('payrollSettings')
                ->get();
            $activeEmployeeIds = $employees->pluck('id');

            $this->incentiveService->releaseIncludedForEmployeesAndMonth($payroll, $activeEmployeeIds, $monthLabel);
            $this->reimbursementService->releaseIncludedForEmployeesAndMonth($payroll, $activeEmployeeIds, $monthLabel);

            // Drop payslips belonging to employees who have since left the run — a
            // deactivation or cycle switch between runs would otherwise strand a
            // payslip here while the totals below are rebuilt from the current set
            // only, leaving total_payout disagreeing with SUM(payslips.net_salary).
            Payslip::where('payroll_id', $payroll->id)
                ->whereNotIn('employee_id', $activeEmployeeIds)
                ->delete();

            $totalPayrollPayout = 0.0;
            $otAmount = 0.0;
            $incentives = 0.0;
            $reimbursements = 0.0;
            $deductions = 0.0;

            foreach ($employees as $employee) {
                $existing = Payslip::where('payroll_id', $payroll->id)
                    ->where('employee_id', $employee->id)
                    ->first();

                // A payslip locked individually (Phase 2) survives a whole-batch
                // regenerate untouched — its existing totals still count toward
                // the payroll header below.
                if ($existing?->isLocked()) {
                    $otAmount += 0.0;
                    $deductions += (float) $existing->total_deductions;
                    $totalPayrollPayout += (float) $existing->net_salary;

                    continue;
                }

                $existing?->delete();

                $result = $this->salaryCalculationService->calculate(
                    $employee,
                    $cycleFrom,
                    $cycleTo,
                    $monthLabel,
                    $payroll,
                );

                $payslip = Payslip::create([
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employee->id,
                    'gross_salary' => $result->gross,
                    'total_deductions' => $result->totalDeductions,
                    'net_salary' => $result->net,
                    'status' => 'draft',
                ]);

                $allItems = array_merge(
                    $result->earningItems,
                    $result->deductionItems,
                    $result->employerContributionItems,
                );
                foreach ($allItems as $item) {
                    $payslip->items()->create($item);
                }

                if ($result->otRecords->isNotEmpty()) {
                    $result->otRecords->each(fn ($record) => $record->update(['payslip_id' => $payslip->id]));
                }

                $otAmount += $result->otAmount;
                $incentives += $result->incentiveAmount;
                $reimbursements += $result->reimbursementAmount;
                $deductions += $result->totalDeductions;
                $totalPayrollPayout += $result->net;
            }

            $payroll->update([
                'processed_by' => $processedBy,
                'processed_at' => now(),
                'status' => 'draft',
                'total_payout' => $totalPayrollPayout,
                'ot_amount' => $otAmount,
                'incentives' => $incentives,
                'reimbursements' => $reimbursements,
                'deductions' => $deductions,
            ]);

            return $payroll->fresh(['payslips.employee.user']);
        });
    }

    /**
     * Submit a draft for approval. With no active PayrollApprovalPolicy steps
     * configured this is the original single-hop flow (notify every
     * finance/director/super_admin, any one of them can approveFinance()
     * directly) — fully unchanged so a fresh install behaves exactly as
     * before. Once an admin configures ≥1 active policy step, submissions
     * snapshot that policy onto payroll_approval_steps and only the first
     * step's approver(s) are notified; later steps unlock in order via
     * approveStep().
     */
    public function submitForFinanceApproval(Payroll $payroll): Payroll
    {
        if ($payroll->status !== 'draft') {
            throw new \DomainException('Only draft payrolls can be submitted for finance approval.');
        }

        $activeSteps = PayrollApprovalPolicy::activeSteps();
        foreach ($activeSteps as $step) {
            $this->assertStepResolvable($step);
        }

        return DB::transaction(function () use ($payroll, $activeSteps) {
            $payroll->update(['status' => 'pending_finance']);
            AuditLog::record($payroll, 'submitted_for_approval', null, ['status' => 'pending_finance']);

            // Clear any stale steps from a prior rejected-and-resubmitted attempt
            // — payrolls are looked up/reused by month+year+cycle, never recreated.
            PayrollApprovalStep::where('payroll_id', $payroll->id)->delete();

            if ($activeSteps->isEmpty()) {
                // Fallback only: with an approval policy configured, the step
                // approvers below are notified instead of everyone who could
                // conceivably approve.
                foreach (app(NotificationRecipients::class)->payrollApprovers() as $approver) {
                    $approver->notify((new PayrollApprovalNotification($payroll->fresh(), 'submitted'))->forRole('approver'));
                }

                return $payroll->fresh(['payslips.employee.user']);
            }

            foreach ($activeSteps as $policyStep) {
                PayrollApprovalStep::create([
                    'payroll_id' => $payroll->id,
                    'level' => $policyStep->level,
                    'label' => $policyStep->label,
                    'approver_type' => $policyStep->approver_type,
                    'specific_user_id' => $policyStep->specific_user_id,
                    'status' => 'pending',
                ]);
            }

            $this->notifyStepApprovers($payroll->fresh(), $payroll->approvalSteps()->first());

            return $payroll->fresh(['payslips.employee.user', 'approvalSteps']);
        });
    }

    public function approveFinance(Payroll $payroll, int $approverId): Payroll
    {
        if ($payroll->status !== 'pending_finance') {
            throw new \DomainException('Only pending payrolls can be approved by finance.');
        }

        if ($payroll->approvalSteps()->exists()) {
            throw new \DomainException('This payroll uses a configured approval flow — act on it via the individual approval steps.');
        }

        // Maker-checker: whoever most recently generated/regenerated this draft
        // (processed_by) cannot also be the one who approves it into finalized.
        if ((int) $payroll->processed_by === $approverId) {
            throw new \DomainException('You processed this payroll — someone else must approve it (maker-checker separation).');
        }

        return DB::transaction(fn () => $this->finalizePayroll($payroll, $approverId));
    }

    public function rejectFinance(Payroll $payroll, ?string $note = null): Payroll
    {
        if ($payroll->status !== 'pending_finance') {
            throw new \DomainException('Only pending payrolls can be rejected by finance.');
        }

        if ($payroll->approvalSteps()->exists()) {
            throw new \DomainException('This payroll uses a configured approval flow — act on it via the individual approval steps.');
        }

        return DB::transaction(fn () => $this->revertToDraft($payroll, $note));
    }

    /**
     * Approve one step of a configured chain. Steps must be approved in
     * order; the same maker-checker rule as the legacy flow applies to
     * every step (not just the last), and the same approver may not act on
     * two different steps of one submission — otherwise a single
     * over-permissioned user could solo-approve a "multi-step" chain.
     */
    public function approveStep(PayrollApprovalStep $step, User $approver): Payroll
    {
        $payroll = $step->payroll;

        $this->assertStepActionable($step, $payroll, $approver);

        return DB::transaction(function () use ($step, $approver, $payroll) {
            $step->update(['status' => 'approved', 'approver_id' => $approver->id, 'acted_at' => now()]);
            AuditLog::record($step, 'approved', null, ['status' => 'approved', 'approver_id' => $approver->id]);

            $next = PayrollApprovalStep::where('payroll_id', $payroll->id)->where('status', 'pending')->orderBy('level')->first();

            if ($next) {
                $this->notifyStepApprovers($payroll, $next);

                return $payroll->fresh(['payslips.employee.user', 'approvalSteps']);
            }

            return $this->finalizePayroll($payroll, $approver->id);
        });
    }

    /** Reject at any step — cascades remaining pending steps to skipped and reverts the payroll to draft, same as legacy rejectFinance(). */
    public function rejectStep(PayrollApprovalStep $step, User $approver, ?string $note = null): Payroll
    {
        $payroll = $step->payroll;

        $this->assertStepActionable($step, $payroll, $approver);

        return DB::transaction(function () use ($step, $approver, $payroll, $note) {
            $step->update(['status' => 'rejected', 'approver_id' => $approver->id, 'acted_at' => now(), 'note' => $note]);
            AuditLog::record($step, 'rejected', null, ['status' => 'rejected'], reason: $note);

            PayrollApprovalStep::where('payroll_id', $payroll->id)
                ->where('status', 'pending')
                ->update(['status' => 'skipped', 'note' => 'Skipped — payroll returned to draft after rejection at an earlier step.']);

            return $this->revertToDraft($payroll, $note);
        });
    }

    /** Shared guards for approveStep()/rejectStep(): pending, in-order, maker-checker, one-approver-per-step, eligibility. */
    private function assertStepActionable(PayrollApprovalStep $step, Payroll $payroll, User $approver): void
    {
        if (! $step->isPending()) {
            throw new \DomainException('This step has already been actioned.');
        }

        $earliest = PayrollApprovalStep::where('payroll_id', $payroll->id)->where('status', 'pending')->orderBy('level')->first();
        if ($earliest?->id !== $step->id) {
            throw new \DomainException('An earlier step is still pending — steps must be approved in order.');
        }

        if ((int) $payroll->processed_by === $approver->id) {
            throw new \DomainException('You processed this payroll — someone else must approve it (maker-checker separation).');
        }

        $priorApprovers = PayrollApprovalStep::where('payroll_id', $payroll->id)->where('status', 'approved')->pluck('approver_id');
        if ($priorApprovers->contains($approver->id)) {
            throw new \DomainException('You already approved an earlier step — a different approver is required at each step.');
        }

        if (! $step->isEligible($approver)) {
            throw new \DomainException('You are not an eligible approver for this step.');
        }
    }

    /** A role-type step needs at least one eligible user; a specific_user step needs that user to still exist. Checked at submit time — a user can be deleted/deactivated after the policy was configured. */
    private function assertStepResolvable(PayrollApprovalPolicy $step): void
    {
        if ($step->approver_type === 'specific_user') {
            if (! $step->specific_user_id || ! User::whereKey($step->specific_user_id)->exists()) {
                throw new \DomainException("Approval step \"{$step->label}\" points to a user who no longer exists — fix it in the approval policy settings before submitting.");
            }

            return;
        }

        if (User::where('role', $step->approver_type)->doesntExist()) {
            throw new \DomainException("Approval step \"{$step->label}\" has no eligible approver (no user with the {$step->approver_type} role) — fix it in the approval policy settings before submitting.");
        }
    }

    /** Notify the resolved approver(s) for a step — every matching user for a role type, or the exact one for specific_user. */
    private function notifyStepApprovers(Payroll $payroll, PayrollApprovalStep $step): void
    {
        $recipients = $step->approver_type === 'specific_user'
            ? User::whereKey($step->specific_user_id)->get()
            : User::where('role', $step->approver_type)->get();

        foreach ($recipients as $recipient) {
            $recipient->notify((new PayrollApprovalNotification($payroll, 'step_ready', $step))->forRole('approver'));
        }
    }

    /** Finalize a payroll — shared by the legacy single-hop approveFinance() and the last step of a configured chain. */
    private function finalizePayroll(Payroll $payroll, int $approverId): Payroll
    {
        $payroll->update([
            'status' => 'finalized',
            'finance_approved_by' => $approverId,
            'finance_approved_at' => now(),
        ]);

        AuditLog::record($payroll, 'approved', null, ['status' => 'finalized', 'finance_approved_by' => $approverId]);

        $payroll->loadMissing('payslips.employee.user', 'processedBy');
        $payroll->payslips()->update(['status' => 'paid']);

        foreach ($payroll->payslips as $payslip) {
            $records = OvertimeRecord::where('payslip_id', $payslip->id)->where('is_paid', false)->get();
            if ($records->isNotEmpty()) {
                $this->overtimeService->markAsPaid($records, $payslip->id);
            }
        }

        $payroll->processedBy?->notify((new PayrollApprovalNotification($payroll, 'finance_approved'))->forRole('employee'));

        return $payroll->fresh(['payslips.employee.user']);
    }

    /** Revert a payroll to draft — shared by the legacy single-hop rejectFinance() and a rejection at any configured step. */
    private function revertToDraft(Payroll $payroll, ?string $note): Payroll
    {
        $this->incentiveService->releaseIncludedForPayroll($payroll);
        $this->reimbursementService->releaseIncludedForPayroll($payroll);

        $payroll->update([
            'status' => 'draft',
            'finance_note' => $note,
            'finance_approved_by' => null,
            'finance_approved_at' => null,
        ]);
        AuditLog::record($payroll, 'rejected', null, ['status' => 'draft'], reason: $note);

        $payroll->loadMissing('processedBy')->processedBy?->notify((new PayrollApprovalNotification($payroll, 'rejected'))->forRole('employee'));

        return $payroll->fresh(['payslips.employee.user']);
    }

    /** Lock a finalized payroll so it can no longer be regenerated or edited. */
    public function lock(Payroll $payroll, int $userId): Payroll
    {
        if ($payroll->status !== 'finalized') {
            throw new \DomainException('Only a finalized payroll can be locked.');
        }

        if ($payroll->isLocked()) {
            throw new \DomainException('This payroll is already locked.');
        }

        $payroll->update(['locked_at' => now(), 'locked_by' => $userId]);
        AuditLog::record($payroll, 'locked', null, ['locked_by' => $userId]);

        return $payroll->fresh();
    }

    /** Unlock a payroll, allowing it to be regenerated again if needed. */
    public function unlock(Payroll $payroll): Payroll
    {
        if (! $payroll->isLocked()) {
            throw new \DomainException('This payroll is not locked.');
        }

        $payroll->update(['locked_at' => null, 'locked_by' => null]);
        AuditLog::record($payroll, 'unlocked');

        return $payroll->fresh();
    }

    /**
     * Assign a salary structure to an employee, replacing whatever
     * employee_salaries rows are currently effective with the structure's
     * components as of the effective date — old rows are closed out
     * (effective_to), never deleted — and record a SalaryRevision, mirroring
     * the effective-dating convention IncrementService::applySalaryUplift()
     * already established for the increment path. This is the only other
     * place employee compensation changes, so it needs the same trail.
     */
    public function assignSalaryStructure(
        SalaryStructure $structure,
        Employee $employee,
        User $actor,
        string $effectiveDate,
        ?string $reason = null,
    ): SalaryRevision {
        $structure->loadMissing('components');

        return DB::transaction(function () use ($structure, $employee, $actor, $effectiveDate, $reason) {
            $effective = Carbon::parse($effectiveDate);

            $currentRows = EmployeeSalary::where('employee_id', $employee->id)
                ->effectiveOn($effective)
                ->with('component')
                ->get();

            $oldCtc = round($currentRows
                ->filter(fn (EmployeeSalary $row) => $row->component?->effective_component_type === 'earning')
                ->sum('amount') * 12, 2);

            foreach ($currentRows as $row) {
                $row->update(['effective_to' => $effective->copy()->subDay()->toDateString()]);
            }

            $snapshot = [];
            $newAnnualEarnings = 0.0;

            foreach ($structure->components as $component) {
                $amount = (float) ($component->pivot->amount ?? $component->default_amount);

                EmployeeSalary::create([
                    'employee_id' => $employee->id,
                    'salary_component_id' => $component->id,
                    'amount' => $amount,
                    'effective_from' => $effective->toDateString(),
                    'effective_to' => null,
                ]);

                if ($component->effective_component_type === 'earning') {
                    $newAnnualEarnings += $amount * 12;
                }

                $old = $currentRows->firstWhere('salary_component_id', $component->id);
                $snapshot[] = [
                    'component' => $component->name,
                    'old_amount' => $old ? (float) $old->amount : 0.0,
                    'new_amount' => $amount,
                ];
            }

            $newCtc = round($newAnnualEarnings, 2);

            $settings = EmployeePayrollSettings::where('employee_id', $employee->id)->first()
                ?? EmployeePayrollSettings::defaults($employee->id);
            $settings->salary_structure_id = $structure->id;
            $settings->ctc = $newCtc;
            $settings->save();

            $revision = SalaryRevision::create([
                'employee_id' => $employee->id,
                'effective_date' => $effective->toDateString(),
                'reason' => $reason ?: "Assigned salary structure: {$structure->name}",
                'approved_by' => $actor->id,
                'old_ctc' => $oldCtc,
                'new_ctc' => $newCtc,
                'structure_snapshot' => $snapshot,
            ]);

            AuditLog::record($revision, 'created', null, $revision->toArray(), subjectEmployeeId: $employee->id);

            $employee->loadMissing('user')->user?->notify(new SalaryStructureAssignedNotification($revision));

            return $revision->fresh();
        });
    }

    /**
     * Recompute a payroll's header totals from its current payslips — the
     * single source of truth used after any operation that touches one
     * payslip in isolation (regenerate/edit/delete a single employee), so the
     * header never drifts from SUM(payslips.*) the way PayrollRerunIntegrityTest
     * guards against for the whole-batch path.
     */
    private function recalculatePayrollTotals(Payroll $payroll): void
    {
        $payroll->update([
            'total_payout' => (float) $payroll->payslips()->sum('net_salary'),
            'deductions' => (float) $payroll->payslips()->sum('total_deductions'),
        ]);
    }

    /** Re-run the calculation engine for one employee inside an existing draft payroll, leaving every other payslip untouched. */
    public function regenerateSinglePayslip(Payroll $payroll, Employee $employee, int $userId): Payslip
    {
        if ($payroll->isLocked()) {
            throw new \DomainException('This payroll is locked and can no longer be regenerated.');
        }

        if ($payroll->status !== 'draft') {
            throw new \DomainException('Only draft payroll payslips can be regenerated.');
        }

        return DB::transaction(function () use ($payroll, $employee) {
            [$cycleFrom, $cycleTo] = $this->resolveCycleDates($payroll->month, $payroll->year, $payroll->cycle);
            $monthLabel = Carbon::parse("1 {$payroll->month} {$payroll->year}")->format('Y-m');

            $existing = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $employee->id)->first();
            if ($existing?->isLocked()) {
                throw new \DomainException('This payslip is locked and can no longer be regenerated.');
            }
            $existing?->delete();

            $result = $this->salaryCalculationService->calculate($employee, $cycleFrom, $cycleTo, $monthLabel, $payroll);

            $payslip = Payslip::create([
                'payroll_id' => $payroll->id,
                'employee_id' => $employee->id,
                'gross_salary' => $result->gross,
                'total_deductions' => $result->totalDeductions,
                'net_salary' => $result->net,
                'status' => 'draft',
            ]);

            foreach (array_merge($result->earningItems, $result->deductionItems, $result->employerContributionItems) as $item) {
                $payslip->items()->create($item);
            }

            if ($result->otRecords->isNotEmpty()) {
                $result->otRecords->each(fn ($record) => $record->update(['payslip_id' => $payslip->id]));
            }

            $this->recalculatePayrollTotals($payroll);
            AuditLog::record($payslip, 'regenerated', null, null, subjectEmployeeId: $employee->id);

            return $payslip->fresh(['items', 'employee.user']);
        });
    }

    /**
     * Replace a draft payslip's earning/deduction/employer-contribution lines
     * with a manually adjusted set and recompute gross/deductions/net from them.
     *
     * @param  array<int, array{name: string, amount: float, type: string}>  $items
     */
    public function updatePayslipItems(Payslip $payslip, array $items, ?string $reason = null): Payslip
    {
        if ($payslip->status !== 'draft') {
            throw new \DomainException('Only draft payslips can be edited.');
        }

        if ($payslip->isLocked() || $payslip->payroll->isLocked()) {
            throw new \DomainException('This payslip is locked and can no longer be edited.');
        }

        return DB::transaction(function () use ($payslip, $items, $reason) {
            $old = $payslip->only(['gross_salary', 'total_deductions', 'net_salary']);

            $payslip->items()->delete();
            $gross = 0.0;
            $deductions = 0.0;
            foreach ($items as $item) {
                $payslip->items()->create($item);
                if ($item['type'] === 'earning') {
                    $gross += (float) $item['amount'];
                } elseif ($item['type'] === 'deduction') {
                    $deductions += (float) $item['amount'];
                }
            }
            $net = $gross - $deductions;

            $payslip->update(['gross_salary' => $gross, 'total_deductions' => $deductions, 'net_salary' => $net]);
            $this->recalculatePayrollTotals($payslip->payroll);

            AuditLog::record(
                $payslip,
                'edited',
                $old,
                ['gross_salary' => $gross, 'total_deductions' => $deductions, 'net_salary' => $net],
                reason: $reason,
                subjectEmployeeId: $payslip->employee_id,
            );

            return $payslip->fresh(['items', 'employee.user']);
        });
    }

    /** Delete a draft payslip and recompute the parent payroll's totals. */
    public function deletePayslip(Payslip $payslip): void
    {
        if ($payslip->status !== 'draft') {
            throw new \DomainException('Only draft payslips can be deleted.');
        }

        if ($payslip->isLocked() || $payslip->payroll->isLocked()) {
            throw new \DomainException('This payslip is locked and cannot be deleted.');
        }

        DB::transaction(function () use ($payslip) {
            $payroll = $payslip->payroll;
            $payslip->delete();
            $this->recalculatePayrollTotals($payroll);
        });
    }

    /** Lock an individual payslip so it survives a payroll-level regenerate untouched. */
    public function lockPayslip(Payslip $payslip, int $userId): Payslip
    {
        if ($payslip->isLocked()) {
            throw new \DomainException('This payslip is already locked.');
        }

        $payslip->update(['locked_at' => now(), 'locked_by' => $userId]);
        AuditLog::record($payslip, 'locked', null, ['locked_by' => $userId], subjectEmployeeId: $payslip->employee_id);

        return $payslip->fresh();
    }

    /** Unlock an individual payslip. */
    public function unlockPayslip(Payslip $payslip): Payslip
    {
        if (! $payslip->isLocked()) {
            throw new \DomainException('This payslip is not locked.');
        }

        $payslip->update(['locked_at' => null, 'locked_by' => null]);
        AuditLog::record($payslip, 'unlocked', null, null, subjectEmployeeId: $payslip->employee_id);

        return $payslip->fresh();
    }

    /** Admin-triggered single payslip email (any employee) — self-service email lives in MyPayslips::emailPayslip(). */
    public function emailPayslip(Payslip $payslip): void
    {
        $email = $payslip->employee->user?->email;

        if (! $email) {
            throw new \DomainException('This employee has no email address on file.');
        }

        Mail::to($email)->queue(new PayslipMail($payslip));
        AuditLog::record($payslip, 'emailed', null, ['to' => $email], subjectEmployeeId: $payslip->employee_id);
    }

    public function dispatchFinalizedPayrollNotifications(Payroll $payroll): void
    {
        $payroll->loadMissing('payslips.employee.user', 'payslips.payroll');

        foreach ($payroll->payslips as $payslip) {
            if (! $payslip->employee->user) {
                continue;
            }

            $payslip->employee->user->notify(new PayslipGeneratedNotification($payslip));
            Mail::to($payslip->employee->user->email)->send(new PayslipMail($payslip));
            AuditLog::record($payslip, 'emailed', null, ['to' => $payslip->employee->user->email], subjectEmployeeId: $payslip->employee_id);
        }
    }

    public function resolveCycleDates(string $month, int $year, string $cycle): array
    {
        $monthNum = Carbon::parse("1 {$month} {$year}")->month;

        if ($cycle === 'cycle_a') {
            $from = Carbon::create($year, $monthNum, 1)->startOfDay();
            $to = $from->copy()->endOfMonth();
        } else {
            $from = Carbon::create($year, $monthNum, 1)
                ->subMonth()
                ->setDay(21)
                ->startOfDay();
            $to = Carbon::create($year, $monthNum, 20)->endOfDay();
        }

        return [$from, $to];
    }
}
