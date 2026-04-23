<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'last_working_day', 'exit_type', 'exit_reason',
    'notice_period_served', 'clearance_done', 'final_settlement_done', 'final_settlement_amount',
    'exit_interview_done', 'interview_notes', 'payroll_id',
    'processed_by', 'processed_at',
])]
class ExitRecord extends Model
{
    protected function casts(): array
    {
        return [
            'last_working_day'        => 'date',
            'processed_at'            => 'datetime',
            'notice_period_served'    => 'boolean',
            'clearance_done'          => 'boolean',
            'final_settlement_done'   => 'boolean',
            'final_settlement_amount' => 'decimal:2',
            'exit_interview_done'     => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function isComplete(): bool
    {
        return $this->clearance_done
            && $this->final_settlement_done
            && $this->exit_interview_done;
    }
}
