<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'leave_type_id', 'department_id', 'level',
    'approver_type', 'approver_user_id',
    'is_active', 'sort_order',
])]
class LeaveApprovalRule extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * Find the best-matching rule for a given leave request.
     * Priority: specific type + specific dept > specific type + any dept > any type + specific dept > global.
     *
     * @param  int  $level  1 = first approver, 2 = second (HR)
     */
    public static function resolveForRequest(LeaveRequest $request, int $level): ?self
    {
        $employee = $request->employee;

        return self::where('level', $level)
            ->where('is_active', true)
            ->orderByRaw('
                CASE
                    WHEN leave_type_id = ? AND department_id = ? THEN 1
                    WHEN leave_type_id = ? AND department_id IS NULL THEN 2
                    WHEN leave_type_id IS NULL AND department_id = ? THEN 3
                    WHEN leave_type_id IS NULL AND department_id IS NULL THEN 4
                    ELSE 5
                END
            ', [
                $request->leave_type_id, $employee?->department_id,
                $request->leave_type_id,
                $employee?->department_id,
            ])
            ->where(function ($q) use ($request, $employee) {
                $q->where(fn ($q2) => $q2
                    ->where('leave_type_id', $request->leave_type_id)
                    ->where('department_id', $employee?->department_id)
                )->orWhere(fn ($q2) => $q2
                    ->where('leave_type_id', $request->leave_type_id)
                    ->whereNull('department_id')
                )->orWhere(fn ($q2) => $q2
                    ->whereNull('leave_type_id')
                    ->where('department_id', $employee?->department_id)
                )->orWhere(fn ($q2) => $q2
                    ->whereNull('leave_type_id')
                    ->whereNull('department_id')
                );
            })
            ->first();
    }
}
