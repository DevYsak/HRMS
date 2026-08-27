<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveEncashment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What a leave audit entry actually says.
 *
 * The audit trail records actions as "created", "updated", "approved" against
 * a model class. That is enough to reconstruct what happened and useless for
 * reading: "LeaveBalanceAdjustment updated" does not tell anyone whether two
 * days were credited by HR or carried in from last year.
 *
 * This maps each entry to a category and a sentence. It derives both from data
 * already recorded — no second write, no duplicate table. An entry that cannot
 * be categorised is reported as uncategorised rather than guessed at.
 */
class LeaveAuditCategoriser
{
    /** Categories, in the order they read most naturally in a filter. */
    public const CATEGORIES = [
        'leave_allocation' => 'Leave Allocation',
        'leave_balance_adjustment' => 'Manual Balance Adjustment',
        'carry_forward' => 'Carry Forward',
        'carry_forward_reversal' => 'Carry Forward Reversal',
        'leave_regularisation' => 'Leave Regularisation',
        'leave_request' => 'Leave Request',
        'leave_approval' => 'Leave Approval',
        'leave_rejection' => 'Leave Rejection',
        'leave_cancellation' => 'Leave Cancellation',
        'leave_encashment' => 'Leave Encashment',
        'leave_policy' => 'Leave Policy',
        'leave_type' => 'Leave Type',
    ];

    /** The audit actions that belong to each category, for querying. */
    private const ACTION_MAP = [
        'carry_forward' => ['leave.carry_forward_applied'],
        'carry_forward_reversal' => ['leave.carry_forward_reversed'],
        'leave_regularisation' => ['leave.regularisation_approved'],
    ];

    /** Is this entry about leave at all? */
    public function isLeaveEntry(AuditLog $log): bool
    {
        return $this->categoryFor($log) !== null;
    }

    /**
     * The category key for an entry, or null when it is not a leave action.
     */
    public function categoryFor(AuditLog $log): ?string
    {
        $action = (string) $log->action;

        foreach (self::ACTION_MAP as $category => $actions) {
            if (in_array($action, $actions, true)) {
                return $category;
            }
        }

        return match ($log->auditable_type) {
            LeaveBalanceAdjustment::class => $this->adjustmentCategory($log),
            LeaveCarryForwardTransaction::class => $action === 'leave.carry_forward_reversed'
                ? 'carry_forward_reversal'
                : 'carry_forward',
            LeaveRequest::class => $this->requestCategory($log),
            LeaveEncashment::class => 'leave_encashment',
            LeavePolicy::class => 'leave_policy',
            LeaveType::class => 'leave_type',
            default => null,
        };
    }

    public function labelFor(AuditLog $log): ?string
    {
        $key = $this->categoryFor($log);

        return $key ? (self::CATEGORIES[$key] ?? Str::headline($key)) : null;
    }

    /**
     * A sentence describing the entry, built from what was recorded.
     *
     * Returns null rather than inventing a description when the entry does not
     * carry enough to say anything true.
     */
    public function summarise(AuditLog $log): ?string
    {
        $new = (array) ($log->new_values ?? []);
        $old = (array) ($log->old_values ?? []);
        $type = $this->leaveTypeName($log, $new, $old);

        return match ($this->categoryFor($log)) {
            'carry_forward' => $this->carryForwardSummary($new),
            'carry_forward_reversal' => isset($new['reversed_days'])
                ? "{$this->num($new['reversed_days'])} day(s) of carry forward reversed"
                : 'Carry-forward transaction reversed',
            'leave_regularisation' => $this->regularisationSummary($new, $old, $type),
            'leave_allocation' => $type
                ? "{$type} allocated: {$this->num($new['days'] ?? 0)} day(s)"
                : null,
            'leave_balance_adjustment' => $this->adjustmentSummary($new, $type),
            'leave_request' => $type ? "{$type} request submitted" : 'Leave request submitted',
            'leave_approval' => $type ? "{$type} request approved" : 'Leave request approved',
            'leave_rejection' => $type ? "{$type} request rejected" : 'Leave request rejected',
            'leave_cancellation' => 'Leave request cancelled',
            'leave_encashment' => isset($new['requested_days'])
                ? "{$this->num($new['requested_days'])} day(s) encashed"
                : 'Leave encashment recorded',
            'leave_policy' => $this->policySummary($new, $old),
            'leave_type' => $type ? "Leave type '{$type}' changed" : 'Leave type changed',
            default => null,
        };
    }

    /**
     * Narrow a query to one category.
     *
     * Categories built from namespaced actions filter on the action; the rest
     * filter on the model, because that is genuinely where the distinction
     * lives.
     *
     * @param  Builder<AuditLog>  $query
     */
    public function scopeToCategory(Builder $query, string $category): Builder
    {
        if (isset(self::ACTION_MAP[$category])) {
            return $query->whereIn('action', self::ACTION_MAP[$category]);
        }

        return match ($category) {
            'leave_allocation' => $query->where('auditable_type', LeaveBalanceAdjustment::class)
                ->where('new_values->source', 'allocation'),
            'leave_balance_adjustment' => $query->where('auditable_type', LeaveBalanceAdjustment::class)
                ->where(function ($q) {
                    $q->whereNull('new_values->source')->orWhere('new_values->source', 'manual');
                }),
            'leave_request' => $query->where('auditable_type', LeaveRequest::class)->where('action', 'created'),
            'leave_approval' => $query->where('auditable_type', LeaveRequest::class)
                ->whereIn('action', ['approved'])
                ->orWhere(function ($q) {
                    $q->where('auditable_type', LeaveRequest::class)->where('new_values->status', 'approved');
                }),
            'leave_rejection' => $query->where('auditable_type', LeaveRequest::class)
                ->where('new_values->status', 'rejected'),
            'leave_cancellation' => $query->where('auditable_type', LeaveRequest::class)
                ->where('new_values->status', 'cancelled'),
            'leave_encashment' => $query->where('auditable_type', LeaveEncashment::class),
            'leave_policy' => $query->where('auditable_type', LeavePolicy::class),
            'leave_type' => $query->where('auditable_type', LeaveType::class),
            default => $query,
        };
    }

    /** Every model whose audit entries are leave-related. */
    public static function leaveModels(): array
    {
        return [
            LeaveBalanceAdjustment::class,
            LeaveCarryForwardTransaction::class,
            LeaveRequest::class,
            LeaveEncashment::class,
            LeavePolicy::class,
            LeaveType::class,
        ];
    }

    private function adjustmentCategory(AuditLog $log): string
    {
        $source = (array) ($log->new_values ?? []);

        return ($source['source'] ?? null) === 'allocation'
            ? 'leave_allocation'
            : 'leave_balance_adjustment';
    }

    private function requestCategory(AuditLog $log): string
    {
        $status = (array) ($log->new_values ?? []);
        $status = $status['status'] ?? null;

        return match ($status) {
            'approved' => 'leave_approval',
            'rejected' => 'leave_rejection',
            'cancelled' => 'leave_cancellation',
            default => $log->action === 'created' ? 'leave_request' : 'leave_request',
        };
    }

    /** @param  array<string, mixed>  $new */
    private function carryForwardSummary(array $new): string
    {
        $days = $this->num($new['applied_days'] ?? $new['carried_forward_days'] ?? 0);
        $from = $new['previous_leave_year'] ?? null;
        $to = $new['current_leave_year'] ?? null;

        if ($from && $to) {
            return "{$days} day(s) carried forward from {$from} to {$to}";
        }

        return "{$days} day(s) carried forward";
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     */
    private function regularisationSummary(array $new, array $old, ?string $type): string
    {
        $from = $new['from_date'] ?? null;
        $was = $old['attendance_status'] ?? null;
        $now = $new['attendance_status'] ?? 'leave';

        $date = $from ? Carbon::parse($from)->format('d M Y') : 'A day';
        $wasLabel = $was === 'no_record' ? 'no record' : ($was ?? 'its previous state');

        return "{$date} converted from {$wasLabel} to ".($type ?? $now);
    }

    /** @param  array<string, mixed>  $new */
    private function adjustmentSummary(array $new, ?string $type): ?string
    {
        if (! isset($new['days'])) {
            return null;
        }

        $sign = ($new['action'] ?? 'credit') === 'debit' ? '-' : '+';

        return trim(($type ?? 'Leave').' '.$sign.$this->num($new['days']).' day(s)');
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     */
    private function policySummary(array $new, array $old): string
    {
        $before = $old['name'] ?? null;
        $after = $new['name'] ?? null;

        return $before && $after && $before !== $after
            ? "Leave policy changed from {$before} to {$after}"
            : 'Leave policy changed';
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     */
    private function leaveTypeName(AuditLog $log, array $new, array $old): ?string
    {
        foreach (['leave_type', 'name'] as $key) {
            if (! empty($new[$key]) && is_string($new[$key])) {
                return $new[$key];
            }
        }

        $id = $new['leave_type_id'] ?? $old['leave_type_id'] ?? null;

        return $id ? LeaveType::withTrashed()->find($id)?->name : null;
    }

    private function num(mixed $value): string
    {
        $float = (float) $value;

        return rtrim(rtrim(number_format($float, 2, '.', ''), '0'), '.') ?: '0';
    }
}
