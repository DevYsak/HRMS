<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'role', 'action', 'auditable_type', 'auditable_id', 'subject_employee_id',
        'old_values', 'new_values', 'reason', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subjectEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'subject_employee_id');
    }

    /**
     * Record an audit entry for any model action.
     *
     * @param  string  $action  created|updated|deleted
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  string|null  $reason  free-text context (e.g. why a payroll was rejected)
     * @param  int|null  $subjectEmployeeId  the employee this event is ABOUT, when different
     *                                       from the mutated model itself (e.g. a Payslip's owner)
     */
    public static function record(
        Model $model,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?int $subjectEmployeeId = null,
    ): void {
        $request = app(Request::class);
        $user = auth()->user();

        static::create([
            'user_id' => $user?->id,
            'role' => $user?->assignedRole?->name,
            'action' => $action,
            'auditable_type' => get_class($model),
            'auditable_id' => $model->getKey(),
            'subject_employee_id' => $subjectEmployeeId ?? static::inferSubjectEmployeeId($model),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /** Best-effort: most audited models carry an employee_id directly. */
    private static function inferSubjectEmployeeId(Model $model): ?int
    {
        $value = $model->getAttribute('employee_id');

        return $value !== null ? (int) $value : null;
    }
}
