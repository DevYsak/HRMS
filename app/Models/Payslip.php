<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['payroll_id', 'employee_id', 'gross_salary', 'total_deductions', 'net_salary', 'payable_snapshot', 'status', 'locked_at', 'locked_by'])]
class Payslip extends Model
{
    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'payable_snapshot' => 'array',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
