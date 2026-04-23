<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'ot_request_id',
    'attendance_id',
    'payslip_id',
    'work_date',
    'total_hours_worked',
    'standard_hours',
    'ot_hours',
    'rate_per_hour',
    'ot_amount',
    'is_paid',
])]
class OvertimeRecord extends Model
{
    protected function casts(): array
    {
        return [
            'work_date'          => 'date',
            'total_hours_worked' => 'decimal:2',
            'standard_hours'     => 'decimal:2',
            'ot_hours'           => 'decimal:2',
            'rate_per_hour'      => 'decimal:2',
            'ot_amount'          => 'decimal:2',
            'is_paid'            => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function otRequest(): BelongsTo
    {
        return $this->belongsTo(OtRequest::class, 'ot_request_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function scopeUnpaid(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_paid', false);
    }
}
