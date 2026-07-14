<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's membership of a department team over time. left_at + is_active
 * preserve history so team moves never delete the record.
 */
#[Fillable([
    'department_team_id', 'employee_id', 'joined_at', 'left_at', 'is_active',
])]
class DepartmentTeamMember extends Model
{
    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'left_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(DepartmentTeam::class, 'department_team_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
