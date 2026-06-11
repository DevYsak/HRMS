<?php

namespace App\DTOs;

use Illuminate\Support\Collection;

readonly class SalaryCalculationResult
{
    /**
     * @param  array<int, array{name: string, amount: float, type: string}>  $earningItems
     * @param  array<int, array{name: string, amount: float, type: string}>  $deductionItems
     * @param  array<int, array{name: string, amount: float, type: string}>  $employerContributionItems
     * @param  Collection  $otRecords  OvertimeRecord instances included — caller links payslip_id after creation
     */
    public function __construct(
        public float $gross,
        public float $totalDeductions,
        public float $net,
        public float $otAmount,
        public float $incentiveAmount,
        public float $reimbursementAmount,
        public float $encashmentAmount,
        public float $exitSettlementAmount,
        public float $lwpDeduction,
        public array $earningItems,
        public array $deductionItems,
        public array $employerContributionItems,
        public Collection $otRecords,
    ) {}
}
