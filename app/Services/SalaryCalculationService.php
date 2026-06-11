<?php

namespace App\Services;

use App\DTOs\SalaryCalculationResult;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\ExitRecord;
use App\Models\LeaveEncashment;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\SalaryComponent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SalaryCalculationService
{
    public function __construct(
        protected LwpService $lwpService,
        protected IncentiveService $incentiveService,
        protected ReimbursementService $reimbursementService,
    ) {}

    public function calculate(
        Employee $employee,
        Carbon $cycleStart,
        Carbon $cycleEnd,
        string $monthLabel,
        Payroll $payroll,
    ): SalaryCalculationResult {
        $settings = $employee->payrollSettings ?? EmployeePayrollSettings::defaults($employee->id);

        // ── Step 1: Load salary rows effective for this cycle ─────────────────
        $salaryRows = EmployeeSalary::with('component')
            ->where('employee_id', $employee->id)
            ->effectiveOn($cycleEnd)
            ->get()
            ->filter(fn ($row) => $row->component && $row->component->is_active);

        // Separate by effective component type
        $earningRows = $salaryRows
            ->filter(fn ($row) => $row->component->effective_component_type === 'earning')
            ->sortBy([['component.display_order', 'asc'], ['component.id', 'asc']]);

        $deductionRows = $salaryRows
            ->filter(fn ($row) => $row->component->effective_component_type === 'deduction')
            ->sortBy([['component.display_order', 'asc'], ['component.id', 'asc']]);

        $employerContributionRows = $salaryRows
            ->filter(fn ($row) => $row->component->effective_component_type === 'employer_contribution')
            ->sortBy([['component.display_order', 'asc'], ['component.id', 'asc']]);

        // ── Step 2: Two-pass earning resolution ───────────────────────────────
        // Pass A: fixed components first → build initial context
        $context = [];
        $runningEarningSum = 0.0;

        foreach ($earningRows as $row) {
            if ($row->component->calculation_type === 'fixed') {
                $amount = (float) $row->amount;
                if ($row->component->code) {
                    $context[$row->component->code] = $amount;
                }
                $runningEarningSum += $amount;
            }
        }

        // CTC approximation for percentage_basis='ctc' — sum of all fixed earnings
        $context['CTC'] = $runningEarningSum;

        // Pass B: percentage and formula components in display_order
        $earningItems = [];
        $runningEarningSum = 0.0;

        foreach ($earningRows as $row) {
            $component = $row->component;
            $amount = $this->resolveComponentAmount($component, $row, $context, $settings, $runningEarningSum);

            if ($component->code) {
                $context[$component->code] = $amount;
            }
            $runningEarningSum += $amount;

            $earningItems[] = [
                'name' => $component->name,
                'amount' => $amount,
                'type' => 'earning',
            ];
        }

        // Update CTC to the real total of all earning components
        $context['CTC'] = $runningEarningSum;
        $earningsSum = $runningEarningSum;

        // ── Step 3: Deduction resolution ──────────────────────────────────────
        $deductionItems = [];
        $deductionsSum = 0.0;

        foreach ($deductionRows as $row) {
            $component = $row->component;
            $amount = $this->resolveComponentAmount($component, $row, $context, $settings, $earningsSum);

            // Statutory gates
            if ($component->is_pf_applicable && ! $settings->pf_enabled) {
                $amount = 0.0;
            } elseif ($component->is_esi_applicable && ! $settings->esi_enabled) {
                $amount = 0.0;
            } elseif (in_array($component->code, ['PROFESSIONAL_TAX', 'PT'], true) && ! $settings->professional_tax_enabled) {
                $amount = 0.0;
            } elseif ($component->code === 'TDS' && ! $settings->tds_enabled) {
                $amount = 0.0;
            }

            if ($component->code) {
                $context[$component->code] = $amount;
            }
            $deductionsSum += $amount;

            $deductionItems[] = [
                'name' => $component->name,
                'amount' => $amount,
                'type' => 'deduction',
            ];
        }

        // ── Step 4: Employer contribution resolution (informational only) ─────
        $employerContributionItems = [];

        foreach ($employerContributionRows as $row) {
            $component = $row->component;
            $amount = $this->resolveComponentAmount($component, $row, $context, $settings, $earningsSum);

            if ($component->is_pf_applicable && ! $settings->pf_enabled) {
                $amount = 0.0;
            }

            $employerContributionItems[] = [
                'name' => $component->name,
                'amount' => $amount,
                'type' => 'employer_contribution',
            ];
        }

        // ── Step 5: OT ────────────────────────────────────────────────────────
        $otAmount = 0.0;
        $otRecords = new Collection;

        if ($settings->ot_eligible) {
            $otRecords = OvertimeRecord::where('employee_id', $employee->id)
                ->unpaid()
                ->whereNull('payslip_id')
                ->whereBetween('work_date', [$cycleStart->toDateString(), $cycleEnd->toDateString()])
                ->whereHas('otRequest', fn ($q) => $q->where('status', 'approved'))
                ->get();

            $otAmount = (float) $otRecords->sum('ot_amount');

            if ($otAmount > 0) {
                $otHours = round((float) $otRecords->sum('ot_hours'), 2);
                $earningItems[] = [
                    'name' => "OT ({$otHours}h)",
                    'amount' => $otAmount,
                    'type' => 'earning',
                ];
            }
        }

        // ── Step 6: Incentives ────────────────────────────────────────────────
        $incentiveAmount = 0.0;

        if ($settings->incentive_eligible) {
            $incentiveInclusion = $this->incentiveService
                ->includeApprovedForEmployeeMonth($employee, $monthLabel, $payroll);
            $incentiveAmount = (float) $incentiveInclusion['total'];

            if ($incentiveAmount > 0) {
                $earningItems[] = $incentiveInclusion['item'];
            }
        }

        // ── Step 7: Reimbursements ────────────────────────────────────────────
        $reimbursementAmount = 0.0;

        if ($settings->reimbursement_eligible) {
            $reimbursementInclusion = $this->reimbursementService
                ->includeApprovedForEmployeeMonth($employee, $monthLabel, $payroll);
            $reimbursementAmount = (float) $reimbursementInclusion['total'];

            if ($reimbursementAmount > 0) {
                $earningItems[] = $reimbursementInclusion['item'];
            }
        }

        // ── Step 8: Exit settlement ───────────────────────────────────────────
        $exitSettlementAmount = 0.0;
        $exitRecord = ExitRecord::where('employee_id', $employee->id)
            ->where('final_settlement_done', true)
            ->whereNull('payroll_id')
            ->first();

        if ($exitRecord && $exitRecord->final_settlement_amount > 0) {
            $exitSettlementAmount = (float) $exitRecord->final_settlement_amount;
            $earningItems[] = [
                'name' => 'Final Settlement',
                'amount' => $exitSettlementAmount,
                'type' => 'earning',
            ];
            $exitRecord->update(['payroll_id' => $payroll->id]);
        }

        // ── Step 9: Encashments ───────────────────────────────────────────────
        $encashmentAmount = 0.0;
        $encashments = LeaveEncashment::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('payout_month', $monthLabel)
            ->where('status', 'approved')
            ->get();

        if ($encashments->isNotEmpty()) {
            $baseGrossForEncashment = $earningsSum > 0 ? $earningsSum : 0;
            $encashmentAmount = $encashments->sum(function ($encashment) use ($baseGrossForEncashment) {
                $dailyRate = $baseGrossForEncashment > 0 ? $baseGrossForEncashment / 26 : 0;
                $multiplier = (float) ($encashment->leaveType?->encashment_rate_multiplier ?? 1.00);

                return round($encashment->requested_days * $dailyRate * $multiplier, 2);
            });

            if ($encashmentAmount > 0) {
                $earningItems[] = [
                    'name' => 'Leave Encashment ('.$encashments->sum('requested_days').'d)',
                    'amount' => $encashmentAmount,
                    'type' => 'earning',
                ];
                $encashments->each(fn ($e) => $e->update(['status' => 'processed']));
            }
        }

        // ── Step 10: LWP deduction ────────────────────────────────────────────
        $lwpDeduction = 0.0;
        $lwp = $this->lwpService->calculate($employee, $cycleStart, $cycleEnd);

        if ($lwp['deduction'] > 0) {
            $lwpDeduction = (float) $lwp['deduction'];
            $deductionsSum += $lwpDeduction;

            foreach ($lwp['items'] as $item) {
                $deductionItems[] = $item;
            }
        }

        // ── Final aggregates ──────────────────────────────────────────────────
        $gross = $earningsSum + $otAmount + $incentiveAmount + $reimbursementAmount
            + $encashmentAmount + $exitSettlementAmount;
        $net = $gross - $deductionsSum;

        return new SalaryCalculationResult(
            gross: round($gross, 2),
            totalDeductions: round($deductionsSum, 2),
            net: round($net, 2),
            otAmount: round($otAmount, 2),
            incentiveAmount: round($incentiveAmount, 2),
            reimbursementAmount: round($reimbursementAmount, 2),
            encashmentAmount: round($encashmentAmount, 2),
            exitSettlementAmount: round($exitSettlementAmount, 2),
            lwpDeduction: round($lwpDeduction, 2),
            earningItems: $earningItems,
            deductionItems: $deductionItems,
            employerContributionItems: $employerContributionItems,
            otRecords: $otRecords,
        );
    }

    /**
     * Resolve the monetary amount for a single salary component row.
     * Handles fixed, percentage, and formula calculation types.
     * HRA per-employee override is applied here.
     */
    private function resolveComponentAmount(
        SalaryComponent $component,
        EmployeeSalary $row,
        array $context,
        EmployeePayrollSettings $settings,
        float $runningGross,
    ): float {
        // HRA gate: disabled entirely if hra_enabled is false
        if ($component->code === 'HRA' && ! $settings->hra_enabled) {
            return 0.0;
        }

        // HRA per-employee percentage override
        if ($component->code === 'HRA' && $settings->hra_enabled && $settings->hra_percentage !== null) {
            $basic = (float) ($context['BASIC'] ?? 0.0);

            return round($basic * (float) $settings->hra_percentage / 100, 2);
        }

        return match ($component->calculation_type) {
            'percentage' => $this->resolvePercentageAmount($component, $row, $context, $runningGross),
            'formula' => $this->resolveFormulaAmount($component, $context),
            default => (float) $row->amount, // 'fixed'
        };
    }

    private function resolvePercentageAmount(
        SalaryComponent $component,
        EmployeeSalary $row,
        array $context,
        float $runningGross,
    ): float {
        $pct = (float) ($component->percentage_value ?? 0);

        $basis = match ($component->percentage_basis ?? 'basic') {
            'basic' => (float) ($context['BASIC'] ?? 0.0),
            'gross' => $runningGross,
            'ctc' => (float) ($context['CTC'] ?? 0.0),
            'component' => (float) ($context[$component->percentageOfComponent?->code ?? ''] ?? 0.0),
            default => (float) ($context['BASIC'] ?? 0.0),
        };

        return round($basis * $pct / 100, 2);
    }

    private function resolveFormulaAmount(
        SalaryComponent $component,
        array $context,
    ): float {
        if (! $component->formula_expression) {
            return 0.0;
        }

        try {
            return round(FormulaEvaluator::evaluate($component->formula_expression, $context), 2);
        } catch (\RuntimeException) {
            return 0.0;
        }
    }
}
