<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'month', 'year', 'status', 'total_payout',
    'ot_amount', 'incentives', 'reimbursements', 'deductions',
    'processed_by', 'processed_at',
    'finance_approved_by', 'finance_approved_at', 'finance_note',
])]
class Payroll extends Model
{
    protected function casts(): array
    {
        return [
            'processed_at'       => 'datetime',
            'finance_approved_at' => 'datetime',
            'ot_amount'          => 'decimal:2',
            'incentives'         => 'decimal:2',
            'reimbursements'     => 'decimal:2',
            'deductions'         => 'decimal:2',
            'total_payout'       => 'decimal:2',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function financeApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_approved_by');
    }

    public function isPendingFinance(): bool
    {
        return $this->status === 'pending_finance';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** Gross total including OT, incentives, reimbursements, minus deductions. */
    public function computeTotal(): float
    {
        return round(
            (float) $this->total_payout
            + (float) $this->ot_amount
            + (float) $this->incentives
            + (float) $this->reimbursements
            - (float) $this->deductions,
            2
        );
    }
}
