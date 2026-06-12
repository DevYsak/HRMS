<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'pf_enabled', 'pf_number',
    'esi_enabled', 'esi_number',
    'professional_tax_enabled',
    'tds_enabled',
    'ot_eligible',
    'incentive_eligible',
    'bonus_eligible',
    'reimbursement_eligible',
    'hra_enabled',
    'hra_percentage',
    'ctc',
    'salary_structure_id',
    'uan_number',
    'ot_rate_per_hour',
    'bank_name',
    'account_number',
    'ifsc_code',
    'pan_number',
    'aadhar_number',
])]
class EmployeePayrollSettings extends Model
{
    protected function casts(): array
    {
        return [
            'pf_enabled' => 'boolean',
            'esi_enabled' => 'boolean',
            'professional_tax_enabled' => 'boolean',
            'tds_enabled' => 'boolean',
            'ot_eligible' => 'boolean',
            'incentive_eligible' => 'boolean',
            'bonus_eligible' => 'boolean',
            'reimbursement_eligible' => 'boolean',
            'hra_enabled' => 'boolean',
            'hra_percentage' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Returns an un-persisted instance with safe defaults that mirror old engine behaviour:
     * PF/ESI/HRA disabled, OT/incentive/reimbursement eligible.
     */
    public static function defaults(int $employeeId): self
    {
        return new self([
            'employee_id' => $employeeId,
            'pf_enabled' => false,
            'pf_number' => null,
            'esi_enabled' => false,
            'esi_number' => null,
            'professional_tax_enabled' => false,
            'tds_enabled' => false,
            'ot_eligible' => true,
            'incentive_eligible' => true,
            'bonus_eligible' => true,
            'reimbursement_eligible' => true,
            'hra_enabled' => false,
            'hra_percentage' => null,
            'ctc' => null,
            'salary_structure_id' => null,
            'uan_number' => null,
            'ot_rate_per_hour' => null,
            'bank_name' => null,
            'account_number' => null,
            'ifsc_code' => null,
            'pan_number' => null,
            'aadhar_number' => null,
        ]);
    }
}
