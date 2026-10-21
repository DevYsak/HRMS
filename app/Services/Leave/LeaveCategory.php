<?php

namespace App\Services\Leave;

use App\Models\LeaveType;

/**
 * The employee-facing leave categories (Leave V2).
 *
 *  - earned   Earned Leave (Paid Leave): ONE shared balance, held by the leave
 *             type the policy names. Casual, Sick, Privilege, Personal… are
 *             reasons in this category: labels for reporting and validation
 *             that draw on that one balance and never hold their own.
 *  - special  Special / statutory leave: Maternity, Paternity, Bereavement…
 *             Its own entitlement, outside Earned Leave, unless HR sets the
 *             type to draw on Earned Leave (consumes_earned_leave).
 *  - comp_off Comp Off: earned by extra work, its own balance.
 *  - unpaid   Unpaid Leave / LWP: no balance required, affects payroll.
 *
 * HR sets the category on each leave type (leave_types.leave_category).
 * Nothing is inferred from "paid = yes": a paid type HR has not categorised is
 * uncategorised (null) and is not offered in the V2 request flow. Only legacy
 * categories whose meaning is unambiguous are derived — comp off, unpaid/LWP
 * and the statutory leave kinds — and HR can override every one of them.
 * Which balance a type charges is LeaveBalanceSource's to answer.
 */
final class LeaveCategory
{
    public const EARNED = 'earned';

    public const SPECIAL = 'special';

    public const COMP_OFF = 'comp_off';

    public const UNPAID = 'unpaid';

    public const ALL = [self::EARNED, self::SPECIAL, self::COMP_OFF, self::UNPAID];

    public const LABELS = [
        self::EARNED => 'Earned Leave',
        self::SPECIAL => 'Special Leave',
        self::COMP_OFF => 'Comp Off',
        self::UNPAID => 'Unpaid Leave',
    ];

    public const DESCRIPTIONS = [
        self::EARNED => 'One paid leave balance. Casual, Sick, Privilege and other reasons all use it.',
        self::SPECIAL => 'Statutory and special leave such as maternity, paternity or bereavement.',
        self::COMP_OFF => 'Earned from extra work, as per policy.',
        self::UNPAID => 'No balance required. Affects payroll.',
    ];

    /** Legacy statutory kinds: special leave, never part of the standard allowance by default. */
    public const STATUTORY_LEGACY = ['maternity', 'paternity', 'bereavement', 'adoption', 'shared_parental', 'parental'];

    /** Legacy categories that are never requested through the V2 flow unless HR says so. */
    public const NOT_REQUESTABLE = ['wfh', 'unauthorized', 'encashment'];

    public static function of(LeaveType $type): ?string
    {
        if ($type->leave_category !== null && $type->leave_category !== '') {
            return in_array($type->leave_category, self::ALL, true) ? $type->leave_category : null;
        }

        $legacy = (string) $type->category;

        return match (true) {
            in_array($legacy, self::NOT_REQUESTABLE, true) => null,
            $legacy === 'comp_off' => self::COMP_OFF,
            in_array($legacy, ['unpaid', 'lwp'], true) => self::UNPAID,
            in_array($legacy, self::STATUTORY_LEGACY, true) => self::SPECIAL,
            // annual / sick / other / custom: HR decides — never inferred from is_paid.
            default => null,
        };
    }

    /** Whether requests of this category draw on a balance at all. */
    public static function usesBalance(string $category): bool
    {
        return $category !== self::UNPAID;
    }

    /** Whether the type's days are pay-relevant leave at all (WFH, unauthorized … are not). */
    public static function isLeave(LeaveType $type): bool
    {
        return ! in_array((string) $type->category, self::NOT_REQUESTABLE, true) || self::of($type) !== null;
    }

    public static function label(?string $category): string
    {
        return $category === null ? 'Uncategorised' : (self::LABELS[$category] ?? ucfirst(str_replace('_', ' ', $category)));
    }
}
