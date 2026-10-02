<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * The one entry point for categorised audit events (Phase 1 safety). It
 * writes through AuditLog::record(), so every event carries the actor, the
 * real actor behind an impersonation, IP, user agent and the request id,
 * with secrets redacted and sensitive identifiers masked.
 *
 * Usage:
 *   app(AuditService::class)->event('EMPLOYEE_ROLE_CHANGED', AuditService::PERMISSIONS, $user,
 *       old: ['role' => 'Employee'], new: ['role' => 'Manager'], subjectEmployeeId: $employee->id);
 */
class AuditService
{
    public const AUTHENTICATION = 'authentication';

    public const EMPLOYEE = 'employee';

    public const LEAVE = 'leave';

    public const ATTENDANCE = 'attendance';

    public const PAYROLL = 'payroll';

    public const PERFORMANCE = 'performance';

    public const PERMISSIONS = 'permissions';

    public const ROLES = 'roles';

    public const SETTINGS = 'settings';

    public const DOCUMENTS = 'documents';

    public const EXPENSES = 'expenses';

    public const ASSETS = 'assets';

    public const APPROVALS = 'approvals';

    public const IMPORTS = 'imports';

    public const EXPORTS = 'exports';

    public const SECURITY = 'security';

    public const DATA_MANAGEMENT = 'data_management';

    public const SYSTEM_JOBS = 'system_jobs';

    public const BIOMETRIC = 'biometric';

    public const OFFBOARDING = 'offboarding';

    public const ONBOARDING = 'onboarding';

    /** @var array<int, string> */
    public const CATEGORIES = [
        self::AUTHENTICATION, self::EMPLOYEE, self::LEAVE, self::ATTENDANCE, self::PAYROLL,
        self::PERFORMANCE, self::PERMISSIONS, self::ROLES, self::SETTINGS, self::DOCUMENTS,
        self::EXPENSES, self::ASSETS, self::APPROVALS, self::IMPORTS, self::EXPORTS,
        self::SECURITY, self::DATA_MANAGEMENT, self::SYSTEM_JOBS, self::BIOMETRIC,
        self::OFFBOARDING, self::ONBOARDING,
    ];

    /**
     * Record a categorised event.
     *
     * @param  string  $event  stable UPPER_SNAKE code, e.g. LEAVE_BALANCE_ADJUSTED
     * @param  string  $category  one of self::CATEGORIES
     * @param  Model  $entity  the record the event is about (the mutated row)
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function event(
        string $event,
        string $category,
        Model $entity,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?int $subjectEmployeeId = null,
        ?string $module = null,
        ?string $action = null,
    ): AuditLog {
        return AuditLog::record(
            $entity,
            $action ?? strtolower($event),
            $old,
            $new,
            $reason,
            $subjectEmployeeId,
            ['module' => $module ?? $category, 'category' => $category, 'event' => $event],
        );
    }
}
