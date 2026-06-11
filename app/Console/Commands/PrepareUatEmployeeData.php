<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\EmployeePayrollSettings;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PerformanceComponent;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Notifications\KpiAssignedNotification;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\OTApprovedNotification;
use App\Notifications\ReviewCycleStartedNotification;
use App\Services\PayrollService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PrepareUatEmployeeData extends Command
{
    protected $signature = 'uat:prepare-employee
                            {email : Email address of the employee to prepare UAT data for}
                            {--periods=2 : Number of historical payroll periods to ensure exist}
                            {--dry-run : Report current state and planned actions without writing anything}';

    protected $description = 'Prepare realistic UAT data (payroll, payslips, KPI, performance review, notifications) for one employee using their existing production-derived configuration.';

    private array $report = [];

    private array $blockers = [];

    public function handle(PayrollService $payrollService): int
    {
        $email = (string) $this->argument('email');
        $periods = max(1, (int) $this->option('periods'));
        $dryRun = (bool) $this->option('dry-run');

        $employee = Employee::with([
            'user', 'department', 'jobTitle', 'payrollSettings', 'salaries.component',
            'leaveBalances.leaveType', 'employeeKpis.component', 'performanceReviews',
            'activeWarnings', 'activePip', 'promotionRecommendations',
        ])->whereHas('user', fn ($q) => $q->where('email', $email))->first();

        if (! $employee || ! $employee->user) {
            $this->error("No employee record linked to a user with email {$email}.");

            return self::FAILURE;
        }

        $user = $employee->user;

        $actor = User::where('role', UserRole::SuperAdmin)->first()
            ?? User::where('role', UserRole::HrAdmin)->first();

        if (! $actor) {
            $this->error('No super_admin or hr_admin user found to act as the processor for these UAT records.');

            return self::FAILURE;
        }

        $this->report[] = '# UAT Data Preparation Report';
        $this->report[] = '';
        $this->report[] = "- Employee: {$user->name} ({$email})";
        $this->report[] = '- Employee code: '.($employee->employee_code ?? 'n/a');
        $this->report[] = '- Department: '.($employee->department->name ?? 'n/a');
        $this->report[] = '- Designation: '.($employee->jobTitle->name ?? 'n/a');
        $this->report[] = '- Status: '.$employee->status->value;
        $this->report[] = '- Salary cycle: '.($employee->salary_cycle ?? 'n/a');
        $this->report[] = '- Generated: '.now()->toDateTimeString().($dryRun ? ' (DRY RUN — no changes made)' : '');
        $this->report[] = '';

        $this->reportPayrollSettings($employee);
        $this->reportSalaryStructure($employee);

        $this->processPayroll($employee, $payrollService, $actor->id, $periods, $dryRun);
        $this->processKpiAndReview($employee, $actor, $dryRun);
        $this->reportWarningsAndPip($employee);
        $this->processNotificationBackfill($employee, $user, $dryRun);
        $this->reportDocuments($employee);
        $this->reportBlockers();

        $report = implode("\n", $this->report);
        $this->line($report);

        if (! $dryRun) {
            $path = 'uat-reports/'.($employee->employee_code ?? $employee->id).'-'.now()->format('Ymd_His').'.md';
            Storage::put($path, $report);
            $this->newLine();
            $this->info('Report written to storage/app/'.$path);
        }

        return self::SUCCESS;
    }

    private function reportPayrollSettings(Employee $employee): void
    {
        $settings = $employee->payrollSettings ?? EmployeePayrollSettings::defaults($employee->id);

        $this->report[] = '## Payroll & Statutory Settings';
        $this->report[] = '';
        $this->report[] = '- PF enabled: '.($settings->pf_enabled ? "yes (PF #{$settings->pf_number})" : 'no');
        $this->report[] = '- ESI enabled: '.($settings->esi_enabled ? "yes (ESI #{$settings->esi_number})" : 'no');
        $this->report[] = '- Professional tax enabled: '.($settings->professional_tax_enabled ? 'yes' : 'no');
        $this->report[] = '- TDS enabled: '.($settings->tds_enabled ? 'yes' : 'no');
        $this->report[] = '- HRA enabled: '.($settings->hra_enabled ? 'yes' : 'no');
        $this->report[] = '- OT eligible: '.($settings->ot_eligible ? 'yes' : 'no');
        $this->report[] = '- Incentive eligible: '.($settings->incentive_eligible ? 'yes' : 'no');
        $this->report[] = '- Reimbursement eligible: '.($settings->reimbursement_eligible ? 'yes' : 'no');

        if (! $employee->payrollSettings) {
            $this->report[] = '- Note: no `employee_payroll_settings` row exists — the payroll engine falls back to safe defaults (PF/ESI/HRA disabled) shown above.';
        }

        $this->report[] = '';
    }

    private function reportSalaryStructure(Employee $employee): void
    {
        $this->report[] = '## Salary Structure (existing components)';
        $this->report[] = '';

        if ($employee->salaries->isEmpty()) {
            $this->report[] = '- None found.';
            $this->blockers[] = 'Employee has no salary structure (employee_salaries) configured — payroll cannot be generated without inventing values.';
        } else {
            foreach ($employee->salaries as $row) {
                $component = $row->component;
                $label = $component?->name ?? 'Unknown component';
                $type = $component?->effective_component_type ?? 'n/a';
                $this->report[] = "- {$label} ({$type}, {$component?->calculation_type}): ".$this->formatAmount($row->amount, $component);
            }
        }

        $this->report[] = '';
    }

    private function formatAmount($amount, $component): string
    {
        if ($component && $component->calculation_type === 'percentage') {
            return ($component->percentage_value ?? 0).'%';
        }

        if ($component && $component->calculation_type === 'formula') {
            return $component->formula_expression ?? 'formula';
        }

        return number_format((float) $amount, 2);
    }

    private function processPayroll(Employee $employee, PayrollService $payrollService, int $actorId, int $periods, bool $dryRun): void
    {
        $this->report[] = '## Payroll Validation';
        $this->report[] = '';

        $cycle = $employee->salary_cycle;

        if (! $employee->status->isActive()) {
            $this->blockers[] = "Employee status is '{$employee->status->value}', not active — the payroll engine excludes non-active employees from payroll runs.";
        }

        if (! $cycle) {
            $this->blockers[] = 'Employee has no salary_cycle configured — cannot run payroll.';
        } elseif (! in_array($cycle, ['cycle_a', 'cycle_b'], true)) {
            $this->blockers[] = "Employee's salary_cycle is '{$cycle}', not 'cycle_a'/'cycle_b' — PayrollService::resolveCycleDates() only special-cases 'cycle_a' (full calendar month); any other value (including '{$cycle}') is treated as 'cycle_b' (21st-to-20th window), which would compute the wrong period dates for this employee. Normalize employees.salary_cycle to 'cycle_a' or 'cycle_b' before running payroll.";
        }

        if (! $employee->status->isActive() || ! $cycle || ! in_array($cycle, ['cycle_a', 'cycle_b'], true) || $employee->salaries->isEmpty()) {
            $this->report[] = '- Skipped: see blockers below.';
            $this->report[] = '';

            return;
        }

        $now = Carbon::now();

        for ($i = $periods; $i >= 0; $i--) {
            $date = $now->copy()->subMonthsNoOverflow($i);
            $month = $date->format('F');
            $year = (int) $date->format('Y');
            $isCurrent = $i === 0;
            $label = $isCurrent ? "{$month} {$year} (current — preview)" : "{$month} {$year} (historical)";

            $existing = Payroll::where('month', $month)->where('year', $year)->where('cycle', $cycle)->first();

            if ($existing && $existing->status !== 'draft') {
                $this->report[] = "### {$label}";
                $this->report[] = '';
                $this->report[] = "- Payroll already {$existing->status} — left untouched.";
                $this->reportPayslip($employee, $existing);
                $this->report[] = '';

                continue;
            }

            if ($dryRun) {
                $this->report[] = "### {$label}";
                $this->report[] = '';
                $this->report[] = $existing
                    ? '- Existing draft payroll would be regenerated and '.($isCurrent ? 'left as draft preview.' : 'finalized.')
                    : '- Would generate '.($isCurrent ? 'a draft preview payroll.' : 'and finalize this payroll.');
                $this->report[] = '';

                continue;
            }

            try {
                $payroll = $payrollService->generateDraft($month, $year, $cycle, $actorId);
            } catch (\DomainException $e) {
                $this->report[] = "### {$label}";
                $this->report[] = '';
                $this->report[] = "- Error generating payroll: {$e->getMessage()}";
                $this->report[] = '';

                continue;
            }

            if (! $isCurrent) {
                $payroll = $payrollService->submitForFinanceApproval($payroll);
                $payroll = $payrollService->approveFinance($payroll, $actorId);
                $payrollService->dispatchFinalizedPayrollNotifications($payroll);
            }

            $this->report[] = "### {$label}";
            $this->report[] = '';
            $this->report[] = '- Status: '.$payroll->status;
            $this->reportPayslip($employee, $payroll);
            $this->report[] = '';
        }
    }

    private function reportPayslip(Employee $employee, Payroll $payroll): void
    {
        $payslip = Payslip::with('items')
            ->where('payroll_id', $payroll->id)
            ->where('employee_id', $employee->id)
            ->first();

        if (! $payslip) {
            $this->report[] = '- No payslip generated for this employee in this cycle (employee may not be active for this cycle).';

            return;
        }

        $this->report[] = "- Payslip #{$payslip->id} ({$payslip->status}): gross ".number_format((float) $payslip->gross_salary, 2)
            .', deductions '.number_format((float) $payslip->total_deductions, 2)
            .', net '.number_format((float) $payslip->net_salary, 2);

        foreach ($payslip->items as $item) {
            $this->report[] = "  - [{$item->type}] {$item->name}: ".number_format((float) $item->amount, 2);
        }
    }

    private function processKpiAndReview(Employee $employee, $actor, bool $dryRun): void
    {
        $this->report[] = '## KPI & Performance Review Validation';
        $this->report[] = '';

        $activeCycle = PerformanceCycle::where('status', 'active')->latest('start_date')->first();

        if (! $activeCycle) {
            $this->blockers[] = 'No active PerformanceCycle exists — KPI assignments and performance review records cannot be created.';
            $this->report[] = '- Skipped: no active performance cycle found.';
            $this->report[] = '';

            return;
        }

        $this->report[] = "- Active performance cycle: {$activeCycle->name} ({$activeCycle->start_date->toDateString()} to {$activeCycle->end_date->toDateString()})";

        $existingKpis = $employee->employeeKpis->where('performance_cycle_id', $activeCycle->id);

        if ($existingKpis->isNotEmpty()) {
            $this->report[] = "- {$existingKpis->count()} existing KPI assignment(s) for this cycle — left untouched:";
            foreach ($existingKpis as $kpi) {
                $this->report[] = "  - {$kpi->component->name}: target {$kpi->target_value}, status {$kpi->status}";
            }
        } else {
            $components = PerformanceComponent::where('template_id', $activeCycle->template_id)->get();

            if ($components->isEmpty()) {
                $components = PerformanceComponent::all();
            }

            if ($components->isEmpty()) {
                $this->blockers[] = 'No PerformanceComponents are configured — cannot create KPI assignments.';
                $this->report[] = '- No KPI components configured — skipped.';
            } elseif ($dryRun) {
                $this->report[] = "- Would create {$components->count()} KPI assignment(s) from existing components: ".$components->pluck('name')->implode(', ');
            } else {
                foreach ($components as $component) {
                    $kpi = EmployeeKpi::create([
                        'employee_id' => $employee->id,
                        'component_id' => $component->id,
                        'performance_cycle_id' => $activeCycle->id,
                        'assigned_by' => $actor->id,
                        'target_value' => $component->target_value ?? 100.0,
                        'achieved_value' => 0.0,
                        'progress_percent' => 0.0,
                        'status' => 'assigned',
                        'due_date' => $activeCycle->end_date,
                    ]);

                    $employee->user->notify(new KpiAssignedNotification($kpi));
                }

                $this->report[] = "- Created {$components->count()} KPI assignment(s) and dispatched KpiAssignedNotification: ".$components->pluck('name')->implode(', ');
            }
        }

        $existingReview = $employee->performanceReviews->where('performance_cycle_id', $activeCycle->id)->first();

        if ($existingReview) {
            $this->report[] = "- Existing performance review #{$existingReview->id} ({$existingReview->type}, {$existingReview->status}) for this cycle — left untouched.";
        } elseif ($dryRun) {
            $this->report[] = '- Would create a self-review PerformanceReview record for this cycle and dispatch ReviewCycleStartedNotification.';
        } else {
            $managerEmployee = $employee->manager_id
                ? Employee::where('user_id', $employee->manager_id)->first()
                : null;

            $reviewCycle = ReviewCycle::where('status', 'active')->first();

            $review = PerformanceReview::create([
                'review_cycle_id' => $reviewCycle?->id,
                'performance_cycle_id' => $activeCycle->id,
                'template_id' => $activeCycle->template_id,
                'employee_id' => $employee->id,
                'reviewer_id' => $managerEmployee?->id,
                'hr_reviewer_id' => $actor->id,
                'type' => 'self',
                'status' => 'draft',
            ]);

            $employee->user->notify(new ReviewCycleStartedNotification($activeCycle));

            $this->report[] = "- Created performance review #{$review->id} (self, draft) and dispatched ReviewCycleStartedNotification.";

            if (! $managerEmployee) {
                $this->report[] = '  - Note: employee has no manager linked — reviewer_id left empty on the review record.';
            }
        }

        $this->report[] = '';
    }

    private function reportWarningsAndPip(Employee $employee): void
    {
        $this->report[] = '## Warnings, PIP & Promotion Records';
        $this->report[] = '';

        $this->report[] = '- Active warnings: '.$employee->activeWarnings->count();
        $this->report[] = '- Active PIP: '.($employee->activePip ? "#{$employee->activePip->id} ({$employee->activePip->status})" : 'none');
        $this->report[] = '- Promotion recommendations: '.$employee->promotionRecommendations->count();
        $this->report[] = '';
    }

    private function processNotificationBackfill(Employee $employee, $user, bool $dryRun): void
    {
        $this->report[] = '## Notification Validation';
        $this->report[] = '';

        $latestOt = OtRequest::where('employee_id', $employee->id)->where('status', 'approved')->latest('reviewed_at')->first();

        if (! $latestOt) {
            $this->report[] = '- No approved OT requests found — no OTApprovedNotification to validate.';
        } elseif ($user->notifications()->where('type', OTApprovedNotification::class)->exists()) {
            $this->report[] = '- OTApprovedNotification already present for this employee.';
        } elseif ($dryRun) {
            $this->report[] = "- Would dispatch OTApprovedNotification for OT request #{$latestOt->id}.";
        } else {
            $user->notify(new OTApprovedNotification($latestOt));
            $this->report[] = "- Dispatched OTApprovedNotification for OT request #{$latestOt->id}.";
        }

        $latestLeave = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'pending', 'cancelled', 'rejected'])
            ->latest('updated_at')
            ->first();

        if (! $latestLeave) {
            $this->report[] = '- No leave requests found — no LeaveRequestNotification to validate.';
        } elseif ($user->notifications()->where('type', LeaveRequestNotification::class)->exists()) {
            $this->report[] = '- LeaveRequestNotification already present for this employee.';
        } elseif ($dryRun) {
            $this->report[] = "- Would dispatch LeaveRequestNotification for leave request #{$latestLeave->id} (status: {$latestLeave->status}).";
        } else {
            $user->notify(new LeaveRequestNotification($latestLeave));
            $this->report[] = "- Dispatched LeaveRequestNotification for leave request #{$latestLeave->id} (status: {$latestLeave->status}).";
        }

        $this->report[] = '- PayslipGeneratedNotification: dispatched automatically for any payroll period finalized above.';
        $this->report[] = '- KpiAssignedNotification / ReviewCycleStartedNotification: dispatched automatically when KPI/review records were created above.';
        $this->report[] = '';
    }

    private function reportDocuments(Employee $employee): void
    {
        $this->report[] = '## Document Validation';
        $this->report[] = '';

        $documents = Document::where('employee_id', $employee->id)->get();

        if ($documents->isEmpty()) {
            $this->report[] = '- No documents on file for this employee.';
        } else {
            foreach ($documents as $document) {
                $ackRequired = $document->requires_acknowledgement ? 'required' : 'not required';
                $ackStatus = $document->requires_acknowledgement
                    ? ($document->isAcknowledgedBy($employee) ? 'acknowledged' : 'pending')
                    : 'n/a';

                $this->report[] = "- {$document->title} ({$document->category}): acknowledgement {$ackRequired}, status: {$ackStatus}";
            }
        }

        $this->report[] = '';
    }

    private function reportBlockers(): void
    {
        $this->report[] = '## Blockers Before Production Go-Live';
        $this->report[] = '';

        if (empty($this->blockers)) {
            $this->report[] = '- None identified.';
        } else {
            foreach ($this->blockers as $blocker) {
                $this->report[] = "- {$blocker}";
            }
        }

        $this->report[] = '';
    }
}
