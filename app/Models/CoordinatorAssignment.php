<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a coordinator monitors: a single employee, or a whole
 * department. Set by HR (assign_coordinators).
 */
#[Fillable(['coordinator_user_id', 'employee_id', 'department_id', 'created_by'])]
class CoordinatorAssignment extends Model
{
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
