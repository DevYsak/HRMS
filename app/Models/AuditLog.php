<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

/**
 * Append-only audit trail. Rows are never updated or deleted through
 * Eloquent — corrections are new events, not edits to history.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    /** Session key under which the impersonating Super Admin's id is kept. */
    public const IMPERSONATOR_SESSION_KEY = 'impersonator_id';

    /** Field names whose values are never written to the log. */
    private const REDACTED = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token', 'secret', 'api_key'];

    /** Field names whose values are masked to their last four characters. */
    private const MASKED = ['account_number', 'bank_account_number', 'pan_number', 'aadhaar_number', 'aadhar_number', 'uan_number', 'esi_number', 'ifsc_code', 'ni_number', 'national_insurance_number', 'passport_number'];

    protected $fillable = [
        'user_id', 'impersonator_id', 'role', 'module', 'category', 'event', 'action',
        'auditable_type', 'auditable_id', 'subject_employee_id',
        'old_values', 'new_values', 'reason', 'ip_address', 'user_agent', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function subjectEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'subject_employee_id');
    }

    /**
     * Record an audit entry for any model action.
     *
     * The actor is the signed-in user, unless $context['actor'] names one —
     * needed when nobody is signed in yet (the Login event fires before the
     * guard holds the user).
     *
     * @param  string  $action  created|updated|deleted, or a domain verb
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  string|null  $reason  free-text context (e.g. why a payroll was rejected)
     * @param  int|null  $subjectEmployeeId  the employee this event is ABOUT, when different
     *                                       from the mutated model itself (e.g. a Payslip's owner)
     * @param  array{module?: string|null, category?: string|null, event?: string|null, actor?: User|null}  $context
     */
    public static function record(
        Model $model,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?int $subjectEmployeeId = null,
        array $context = [],
    ): self {
        $request = app(Request::class);
        $user = array_key_exists('actor', $context) ? $context['actor'] : auth()->user();

        return static::create([
            'user_id' => $user?->id,
            'impersonator_id' => static::impersonatorId($request),
            'role' => $user?->assignedRole?->name,
            'module' => $context['module'] ?? null,
            'category' => $context['category'] ?? null,
            'event' => $context['event'] ?? null,
            'action' => $action,
            'auditable_type' => get_class($model),
            'auditable_id' => $model->getKey() ?? 0,
            'subject_employee_id' => $subjectEmployeeId ?? static::inferSubjectEmployeeId($model),
            'old_values' => static::sanitize($oldValues),
            'new_values' => static::sanitize($newValues),
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => static::requestId($request),
        ]);
    }

    /**
     * Strip secrets and mask sensitive identifiers before they are stored.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            $field = Str::lower((string) $key);

            if (is_array($value)) {
                $values[$key] = static::sanitize($value);
            } elseif (in_array($field, self::REDACTED, true) || Str::contains($field, ['password', 'secret', 'token'])) {
                $values[$key] = '[redacted]';
            } elseif (in_array($field, self::MASKED, true) && $value !== null && $value !== '') {
                $values[$key] = static::mask((string) $value);
            }
        }

        return $values;
    }

    private static function mask(string $value): string
    {
        return strlen($value) <= 4 ? '****' : str_repeat('*', strlen($value) - 4).substr($value, -4);
    }

    /** The Super Admin behind an impersonated session, if any. */
    private static function impersonatorId(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::IMPERSONATOR_SESSION_KEY);

        return $id !== null ? (int) $id : null;
    }

    /** One correlation id per request so every event from one action groups together. */
    private static function requestId(Request $request): string
    {
        if (! $request->attributes->has('audit_request_id')) {
            $request->attributes->set('audit_request_id', (string) ($request->header('X-Request-Id') ?: Str::uuid()));
        }

        return Str::limit((string) $request->attributes->get('audit_request_id'), 64, '');
    }

    /** Best-effort: most audited models carry an employee_id directly. */
    private static function inferSubjectEmployeeId(Model $model): ?int
    {
        $value = $model->getAttribute('employee_id');

        return $value !== null ? (int) $value : null;
    }
}
