<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One late correction to a finalised payslip's unpaid days, applied in a
 * later run: positive days = arrear deduction, negative = refund.
 */
#[Fillable(['employee_id', 'source_payslip_id', 'payroll_id', 'applied_payslip_id', 'days', 'amount', 'reason'])]
class PayslipDayAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'days' => 'float',
            'amount' => 'float',
        ];
    }

    public function sourcePayslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'source_payslip_id');
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }
}
