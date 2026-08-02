<?php

namespace App\Services\Profile;

use App\Models\Employee;

/**
 * Single source of truth for who may change which profile field.
 *
 * Every profile surface — the employee's own page, the HR admin page, the
 * change-request workflow and the validation layer — reads its rules from
 * here. Re-tiering a field is a one-line edit in FIELDS; no Blade template
 * or component decides for itself whether something is locked.
 *
 * Three tiers, chosen by who bears the consequence of the value being wrong:
 *   EDITABLE — only the employee is affected. Saves immediately.
 *   APPROVAL — legal, payroll or compliance weight. Writes a request; the old
 *              value stays live until HR approves it.
 *   LOCKED   — drives money or hierarchy. Set by HR process, never self-service.
 *
 * Fields live across three tables, so `source` tells readers and writers where
 * to look: the User record, the Employee record, or its payroll settings.
 */
class ProfileFieldRegistry
{
    public const TIER_EDITABLE = 'editable';

    public const TIER_APPROVAL = 'approval';

    public const TIER_LOCKED = 'locked';

    public const SOURCE_USER = 'user';

    public const SOURCE_EMPLOYEE = 'employee';

    public const SOURCE_PAYROLL = 'payroll';

    /**
     * Only fields that exist as real columns today are registered. Adding
     * marital status, education or skills later is one entry here plus a
     * migration — nothing else has to change.
     *
     * @var array<string, array{label:string, tier:string, group:string, source:string, type:string, rules:array<int,string>, reason?:string, relation?:string}>
     */
    public const FIELDS = [
        // ── Employee editable ────────────────────────────────────────────
        'photo' => [
            'label' => 'Profile photo',
            'tier' => self::TIER_EDITABLE,
            'group' => 'identity',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'image',
            'rules' => ['nullable', 'image', 'max:2048'],
        ],
        'phone' => [
            'label' => 'Phone',
            'tier' => self::TIER_EDITABLE,
            'group' => 'contact',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'tel',
            'rules' => ['nullable', 'string', 'max:30'],
        ],
        'emergency_contact' => [
            'label' => 'Emergency contact',
            'tier' => self::TIER_EDITABLE,
            'group' => 'contact',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'tel',
            'rules' => ['nullable', 'string', 'max:30'],
        ],

        // ── Requires HR approval ─────────────────────────────────────────
        'name' => [
            'label' => 'Full name',
            'tier' => self::TIER_APPROVAL,
            'group' => 'identity',
            'source' => self::SOURCE_USER,
            'type' => 'text',
            'rules' => ['required', 'string', 'max:255'],
        ],
        'date_of_birth' => [
            'label' => 'Date of birth',
            'tier' => self::TIER_APPROVAL,
            'group' => 'identity',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'date',
            'rules' => ['nullable', 'date', 'before:today'],
        ],
        'gender' => [
            'label' => 'Gender',
            'tier' => self::TIER_APPROVAL,
            'group' => 'identity',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'select',
            'rules' => ['nullable', 'in:male,female,other'],
        ],
        'address' => [
            'label' => 'Residential address',
            'tier' => self::TIER_APPROVAL,
            'group' => 'contact',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'textarea',
            'rules' => ['nullable', 'string', 'max:500'],
        ],
        'bank_name' => [
            'label' => 'Bank name',
            'tier' => self::TIER_APPROVAL,
            'group' => 'financial',
            'source' => self::SOURCE_PAYROLL,
            'type' => 'text',
            'rules' => ['nullable', 'string', 'max:120'],
        ],
        'account_number' => [
            'label' => 'Account number',
            'tier' => self::TIER_APPROVAL,
            'group' => 'financial',
            'source' => self::SOURCE_PAYROLL,
            'type' => 'text',
            'rules' => ['nullable', 'string', 'max:30'],
        ],
        'ifsc_code' => [
            'label' => 'IFSC code',
            'tier' => self::TIER_APPROVAL,
            'group' => 'financial',
            'source' => self::SOURCE_PAYROLL,
            'type' => 'text',
            'rules' => ['nullable', 'string', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
        ],
        'pan_number' => [
            'label' => 'PAN',
            'tier' => self::TIER_APPROVAL,
            'group' => 'financial',
            'source' => self::SOURCE_PAYROLL,
            'type' => 'text',
            'rules' => ['nullable', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
        ],
        'aadhar_number' => [
            'label' => 'Aadhaar',
            'tier' => self::TIER_APPROVAL,
            'group' => 'financial',
            'source' => self::SOURCE_PAYROLL,
            'type' => 'text',
            'rules' => ['nullable', 'digits:12'],
        ],

        // ── Managed by HR ────────────────────────────────────────────────
        'employee_id' => [
            'label' => 'Employee ID',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'text',
            'rules' => ['required', 'string', 'max:50'],
            'reason' => 'Your Employee ID is assigned by HR and never changes.',
        ],
        'employee_code' => [
            'label' => 'Biometric code',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'number',
            'rules' => ['nullable', 'integer'],
            'reason' => 'Links you to the attendance device. Changed by HR only.',
        ],
        'email' => [
            'label' => 'Work email',
            'tier' => self::TIER_LOCKED,
            'group' => 'contact',
            'source' => self::SOURCE_USER,
            'type' => 'email',
            'rules' => ['required', 'email', 'max:255'],
            'reason' => 'Your work email is also your login. Contact HR or IT to change it.',
        ],
        'department_id' => [
            'label' => 'Department',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'relation',
            'relation' => 'department',
            'rules' => ['nullable', 'exists:departments,id'],
            'reason' => 'Set by HR as part of your placement.',
        ],
        'job_title_id' => [
            'label' => 'Designation',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'relation',
            'relation' => 'jobTitle',
            'rules' => ['nullable', 'exists:job_titles,id'],
            'reason' => 'Designation changes go through the promotion workflow.',
        ],
        'manager_id' => [
            'label' => 'Reporting manager',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'relation',
            'relation' => 'manager',
            'rules' => ['nullable', 'exists:users,id'],
            'reason' => 'Reporting lines are managed by HR.',
        ],
        'office_id' => [
            'label' => 'Office / branch',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'relation',
            'relation' => 'office',
            'rules' => ['nullable', 'exists:offices,id'],
            'reason' => 'Set by HR as part of your placement.',
        ],
        'shift_id' => [
            'label' => 'Shift',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'relation',
            'relation' => 'shift',
            'rules' => ['nullable', 'exists:shift_settings,id'],
            'reason' => 'Your shift is assigned by HR and drives attendance rules.',
        ],
        'employment_type_id' => [
            'label' => 'Employment type',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'relation',
            'relation' => 'employmentType',
            'rules' => ['nullable', 'exists:employment_types,id'],
            'reason' => 'Defined by your contract.',
        ],
        'joining_date' => [
            'label' => 'Joining date',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'date',
            'rules' => ['nullable', 'date'],
            'reason' => 'Drives probation, leave accrual and payroll. HR only.',
        ],
        'status' => [
            'label' => 'Employment status',
            'tier' => self::TIER_LOCKED,
            'group' => 'employment',
            'source' => self::SOURCE_EMPLOYEE,
            'type' => 'text',
            'rules' => ['required', 'string'],
            'reason' => 'Managed through the employee lifecycle workflow.',
        ],
        'ctc' => [
            'label' => 'Annual CTC',
            'tier' => self::TIER_LOCKED,
            'group' => 'financial',
            'source' => self::SOURCE_PAYROLL,
            'type' => 'number',
            'rules' => ['nullable', 'numeric', 'min:0'],
            'reason' => 'Compensation is managed by HR and Finance.',
        ],
    ];

    /** Display order and heading for each field group. */
    public const GROUPS = [
        'identity' => 'Identity',
        'contact' => 'Contact',
        'employment' => 'Employment',
        'financial' => 'Financial',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::FIELDS;
    }

    public static function has(string $key): bool
    {
        return isset(self::FIELDS[$key]);
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::FIELDS[$key] ?? null;
    }

    /** Unknown keys are treated as locked — fail closed, never fail open. */
    public static function tier(string $key): string
    {
        return self::FIELDS[$key]['tier'] ?? self::TIER_LOCKED;
    }

    public static function isEditable(string $key): bool
    {
        return self::tier($key) === self::TIER_EDITABLE;
    }

    public static function needsApproval(string $key): bool
    {
        return self::tier($key) === self::TIER_APPROVAL;
    }

    public static function isLocked(string $key): bool
    {
        return self::tier($key) === self::TIER_LOCKED;
    }

    public static function label(string $key): string
    {
        return self::FIELDS[$key]['label'] ?? str_replace('_', ' ', ucfirst($key));
    }

    /** Why a locked field can't be edited — shown in the lock tooltip. */
    public static function lockReason(string $key): string
    {
        return self::FIELDS[$key]['reason'] ?? 'Managed by HR.';
    }

    /** @return array<int, string> */
    public static function rulesFor(string $key): array
    {
        return self::FIELDS[$key]['rules'] ?? ['nullable'];
    }

    /** @return array<int, string> */
    public static function keysByTier(string $tier): array
    {
        return array_keys(array_filter(self::FIELDS, fn ($f) => $f['tier'] === $tier));
    }

    /**
     * Fields belonging to one group, in declaration order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function group(string $group): array
    {
        return array_filter(self::FIELDS, fn ($f) => $f['group'] === $group);
    }

    /** Which model actually stores a field, so callers never guess. */
    public static function source(string $key): string
    {
        return self::FIELDS[$key]['source'] ?? self::SOURCE_EMPLOYEE;
    }

    /**
     * The raw stored value, resolved from whichever table holds it.
     */
    public static function valueFor(Employee $employee, string $key): mixed
    {
        return match (self::source($key)) {
            self::SOURCE_USER => $employee->user?->{$key},
            self::SOURCE_PAYROLL => $employee->payrollSettings?->{$key},
            default => $employee->{$key},
        };
    }

    /**
     * The value as a human reads it — relations resolved to names, dates
     * formatted, enums cast to their label. Used by every read-only field row.
     */
    public static function displayValueFor(Employee $employee, string $key): ?string
    {
        $field = self::get($key);
        $value = self::valueFor($employee, $key);

        if ($field && ($field['type'] ?? null) === 'relation') {
            $related = $employee->{$field['relation']};

            return $related?->name ?? $related?->user?->name;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y');
        }

        if ($value instanceof \BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        return (string) $value;
    }
}
