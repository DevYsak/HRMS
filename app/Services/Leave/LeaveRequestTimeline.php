<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePaymentAuditLog;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One leave request's complete history, oldest first: submission, HR's
 * proposed changes and the employee's answers, approvals and rejections,
 * the paid days deducted (with their ledger entries) and the unpaid days
 * sent to payroll, paid / unpaid changes, cancellation requests and their
 * decisions, and every balance reversal.
 *
 * Read only. Built from the audit log, the leave ledger and the payment audit
 * log — four queries whatever the length of the history. An event with no
 * user is labelled as the system process that did it, never as a person.
 */
class LeaveRequestTimeline
{
    /** Field-level observer rows that only repeat a categorised event. */
    private const RAW_ACTIONS = ['created', 'updated', 'deleted', 'restored'];

    /** Insertion order: the tie-break for events recorded in the same second. */
    private int $order = 0;

    /**
     * @return Collection<int, array{at: Carbon, actor: string, system: bool, action: string, before: array<string, mixed>, after: array<string, mixed>, reason: ?string, ledger_ids: array<int, int>, kind: string}>
     */
    public function for(LeaveRequest $request): Collection
    {
        $audit = AuditLog::with('user')->where('auditable_type', LeaveRequest::class)->where('auditable_id', $request->id)->orderBy('id')->get();
        $ledger = LeaveLedgerEntry::with(['creator', 'leaveType' => fn ($q) => $q->withTrashed()])
            ->whereIn('source_type', ['leave_request', 'leave_request_return'])->where('source_id', $request->id)->orderBy('id')->get();
        $payments = LeavePaymentAuditLog::with('changedByUser')->where('leave_request_id', $request->id)->orderBy('id')->get();

        $events = collect();

        // Submission: the categorised event when there is one, else the row itself.
        if (! $audit->contains(fn (AuditLog $a) => in_array($a->event, ['LEAVE_SUBMITTED', 'LEAVE_COMBINED_SUBMITTED', 'LEAVE_APPLIED_ON_BEHALF'], true))) {
            $events->push($this->event($request->created_at, ($request->applied_by_user_id ? User::find($request->applied_by_user_id)?->name : null) ?? $request->employee?->user?->name, 'Submitted', [], [
                'dates' => LeaveRequestPresenter::dates($request), 'days' => (float) $request->days,
            ], null, kind: 'submitted'));
        }

        foreach ($audit as $row) {
            if ($row->event === null && in_array($row->action, self::RAW_ACTIONS, true)) {
                continue;
            }
            $events->push($this->event($row->created_at, $row->user?->name, $this->label($row), (array) $row->old_values, (array) $row->new_values,
                $row->reason, kind: 'audit', system: $this->systemLabel($row)));
        }

        foreach ($ledger as $entry) {
            $reversal = $entry->reverses_entry_id !== null;
            $credit = (float) $entry->days > 0;
            $action = match (true) {
                $reversal => 'Balance reversal',
                $entry->source_type === 'leave_request_return' => 'Days returned to the balance',
                $credit => 'Balance credited',
                default => 'Paid leave deducted',
            };
            $events->push($this->event($entry->created_at, $entry->creator?->name, $action, [], [
                'balance' => $entry->leaveType?->name, 'days' => (float) $entry->days, 'effective' => $entry->effective_date?->toDateString(),
            ] + ($reversal ? ['reverses_entry' => $entry->reverses_entry_id] : []), $entry->reason, [$entry->id], 'ledger', $entry->creator ? null : 'System'));
        }

        foreach ($payments as $log) {
            $events->push($this->event($log->created_at, $log->changedByUser?->name, 'Paid / unpaid changed',
                ['payment' => $log->from_status], ['payment' => $log->to_status, 'stage' => $log->stage], $log->reason, kind: 'payment'));
        }

        // The unpaid days payroll treats as Leave Without Pay, at the approval.
        $unpaid = LeaveRequestPresenter::unpaid($request);
        if ($unpaid > 0 && $request->status === 'approved' && $request->approved_at) {
            $events->push($this->event($request->approved_at, null, 'Unpaid days sent to payroll', [], [
                'lwp_days' => $unpaid, 'payroll_month' => $request->start_date->format('M Y'),
            ], null, kind: 'payroll', system: 'Payroll (on approval)'));
        }

        return $events->sortBy([fn ($a, $b) => $a['at'] <=> $b['at'], fn ($a, $b) => $a['order'] <=> $b['order']])
            ->values()->map(fn (array $e) => collect($e)->except('order')->all());
    }

    /** @return array<string, mixed> */
    private function event(?\DateTimeInterface $at, ?string $user, string $action, array $before, array $after, ?string $reason, array $ledgerIds = [], string $kind = 'audit', ?string $system = null): array
    {
        return [
            'at' => Carbon::parse($at ?? now()),
            'actor' => $user ?? ($system ?? 'System'),
            'system' => $user === null,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ledger_ids' => $ledgerIds,
            'kind' => $kind,
            'order' => $this->order++,
        ];
    }

    private function label(AuditLog $row): string
    {
        return match ($row->event) {
            'LEAVE_SUBMITTED', 'LEAVE_COMBINED_SUBMITTED' => 'Submitted',
            'LEAVE_APPLIED_ON_BEHALF' => 'Submitted by HR on the employee\'s behalf',
            'LEAVE_APPROVED' => 'Approved',
            'LEAVE_REJECTED' => 'Rejected',
            'LEAVE_FORWARDED_TO_HR' => 'Approved by manager — forwarded to HR',
            'LEAVE_CANCELLED' => 'Cancelled',
            'LEAVE_DECISION_CHANGED' => 'Decision corrected by HR',
            'LEAVE_CHANGE_PROPOSED' => 'HR proposed changes',
            'LEAVE_CHANGE_ACCEPTED' => 'Employee accepted the proposed changes',
            'LEAVE_CHANGE_REJECTED' => 'Employee rejected the proposed changes',
            'LEAVE_CHANGE_WITHDRAWN' => 'HR withdrew the proposed changes',
            'LEAVE_CANCELLATION_REQUESTED' => 'Cancellation requested',
            'LEAVE_CANCELLATION_WITHDRAWN' => 'Cancellation request withdrawn',
            'LEAVE_CANCELLATION_APPROVED' => 'Cancellation approved',
            'LEAVE_CANCELLATION_REJECTED' => 'Cancellation rejected',
            null => ucfirst(str_replace('_', ' ', (string) $row->action)),
            default => ucfirst(strtolower(str_replace(['LEAVE_', '_'], ['', ' '], $row->event))),
        };
    }

    /** Who acted when no user did: the process, as the row records it. */
    private function systemLabel(AuditLog $row): ?string
    {
        if ($row->user_id !== null) {
            return null;
        }
        $trigger = $row->new_values['trigger'] ?? $row->new_values['actor'] ?? null;

        return $trigger ? 'System ('.str_replace('system:', '', (string) $trigger).')' : 'System';
    }
}
