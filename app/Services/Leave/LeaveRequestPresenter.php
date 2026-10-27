<?php

namespace App\Services\Leave;

use App\Models\LeaveRequest;
use App\Models\LeaveType;

/**
 * How a leave request reads on HR screens: its category, its reason, the
 * paid / unpaid split and whether it predates Leave V2. Display only —
 * every figure comes from the request row as stored.
 */
class LeaveRequestPresenter
{
    public const STATUS = [
        'pending' => ['Pending', 'amber'],
        'pending_hr' => ['Pending HR', 'amber'],
        'more_info_requested' => ['Needs info', 'blue'],
        'awaiting_employee_confirmation' => ['Awaiting employee', 'violet'],
        'approved' => ['Approved', 'emerald'],
        'rejected' => ['Rejected', 'rose'],
        'cancelled' => ['Cancelled', 'zinc'],
    ];

    /** Combined Leave, a V2 category (Earned, Special…), or a legacy type's own name. */
    public static function category(LeaveRequest $request): string
    {
        return match (true) {
            $request->isCombined() => 'Combined Leave',
            (bool) $request->main_category => LeaveCategory::label($request->main_category),
            default => $request->leaveType?->name ?? 'Leave',
        };
    }

    /** The reason under a V2 category (Casual, Sick, Paternity…); null for a legacy request. */
    public static function reason(LeaveRequest $request): ?string
    {
        return ($request->isCombined() || $request->main_category) ? $request->leaveType?->name : null;
    }

    /** Units on Leave Without Pay: an unpaid type's whole request, else the stored LWP portion. */
    public static function unpaid(LeaveRequest $request): float
    {
        return $request->leaveType && ! $request->leaveType->is_paid ? (float) $request->days : $request->unpaidUnits();
    }

    public static function paid(LeaveRequest $request): float
    {
        return round(max(0.0, (float) $request->days - self::unpaid($request)), 2);
    }

    /** A retired or historical-only type, or a request filed before Leave V2 categories existed. */
    public static function isLegacy(LeaveRequest $request): bool
    {
        $type = $request->leaveType;

        return ($type && ($type->trashed() || ($type->availability ?? LeaveType::AVAILABILITY_ACTIVE) !== LeaveType::AVAILABILITY_ACTIVE))
            || (LeavePolicyEngine::enabled() && ! $request->main_category && ! $request->isCombined());
    }

    /** @return array{0: string, 1: string} label, badge colour */
    public static function status(string $status): array
    {
        return self::STATUS[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'zinc'];
    }

    public static function reference(LeaveRequest $request): string
    {
        return 'LR-'.str_pad((string) $request->id, 4, '0', STR_PAD_LEFT);
    }

    public static function dates(LeaveRequest $request): string
    {
        return $request->start_date->format('d M Y').($request->end_date->eq($request->start_date) ? '' : ' – '.$request->end_date->format('d M Y'));
    }

    public static function isPending(LeaveRequest $request): bool
    {
        return in_array($request->status, ['pending', 'pending_hr', 'more_info_requested'], true);
    }
}
