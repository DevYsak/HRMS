<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'effective_date', 'reason',
    'approved_by', 'old_ctc', 'new_ctc', 'structure_snapshot',
])]
class SalaryRevision extends Model
{
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'old_ctc' => 'decimal:2',
            'new_ctc' => 'decimal:2',
            'structure_snapshot' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
