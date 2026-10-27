<?php

namespace App\Services\Leave;

use App\Models\LeaveRequest;
use App\Models\LeaveRequestRevision;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRevisionNotification;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Audit\AuditService;
use App\Services\LeaveService;
use App\Services\Notifications\NotificationRecipients;
use App\Services\Security\ScopeResolver;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HR editing an open leave request, without ever overwriting what the
 * employee submitted.
 *
 * HR proposes a change (dates, full / half day, session, the reason, the paid
 * / unpaid split, or a Combined request's date-wise allocation). The proposal
 * is stored beside the original as a revision and the request waits in
 * 'awaiting_employee_confirmation'. The employee accepts — the proposed
 * version becomes the request and it goes back to HR for the final decision —
 * or rejects, and the request stays exactly as submitted for HR to revise or
 * reject. Nothing is ever deducted here: only final approval deducts.
 *
 * Every step runs in a transaction, locks the request, and is audited.
 */
class LeaveRequestRevisionService
{
    /** Open requests HR may propose a change to. */
    public const PROPOSABLE = ['pending', 'pending_hr', 'more_info_requested'];

    /** The fields compared to decide what changed; every one of them needs the employee's confirmation. */
    public const MATERIAL = [
        'start_date', 'end_date', 'is_half_day', 'half_day_period', 'leave_type_id',
        'days', 'lwp_days', 'requested_leave_status', 'allocation',
    ];

    public function __construct(
        private readonly LeaveService $leave,
        private readonly ApprovalGuard $guard,
    ) {}

    /** HR (leave management + approve leave) in scope, never on their own request. */
    public function mayPropose(LeaveRequest $request, User $user): bool
    {
        return in_array($request->status, self::PROPOSABLE, true) && $this->isHrFor($request, $user);
    }

    public function mayWithdraw(LeaveRequestRevision $revision, User $user): bool
    {
        return $revision->isAwaiting() && $this->isHrFor($revision->leaveRequest, $user);
    }

    /**
     * The proposed version, worked out exactly as approval would, and what it
     * changes. Writes nothing.
     *
     * @param  array{start_date?: string, end_date?: string, is_half_day?: bool, half_day_period?: ?string, leave_type_id?: int|string, paid_days?: float|string|null, allocations?: array<string, array{type?: string, session?: ?string}>}  $input
     * @return array{original: array<string, mixed>, proposed: array<string, mixed>, changed: array<int, string>}
     */
    public function preview(LeaveRequest $request, array $input): array
    {
        $original = $this->snapshot($request);
        $proposed = $this->proposal($request, $input);

        return ['original' => $original, 'proposed' => $proposed, 'changed' => $this->changed($original, $proposed)];
    }

    /** HR proposes a change; the request waits for the employee. */
    public function propose(LeaveRequest $request, User $hr, array $input, string $reason): LeaveRequestRevision
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \DomainException('Tell the employee why, in at least 5 characters.');
        }

        $revision = DB::transaction(function () use ($request, $hr, $input, $reason) {
            $locked = LeaveRequest::lockForUpdate()->findOrFail($request->id);
            if (! $this->mayPropose($locked, $hr)) {
                throw new AuthorizationException('You may not propose changes to this leave request.');
            }

            ['original' => $original, 'proposed' => $proposed, 'changed' => $changed] = $this->preview($locked, $input);
            if ($changed === []) {
                throw new \DomainException('The proposal is the same as the request — nothing to confirm.');
            }

            $revision = LeaveRequestRevision::create([
                'leave_request_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'proposed_by' => $hr->id,
                'original' => $original + ['status' => $locked->status],
                'proposed' => $proposed,
                'changed_fields' => $changed,
                'reason' => $reason,
                'requires_confirmation' => true,
                'status' => LeaveRequestRevision::AWAITING,
            ]);

            $locked->update(['status' => LeaveRequest::AWAITING_EMPLOYEE]);

            app(AuditService::class)->event('LEAVE_CHANGE_PROPOSED', AuditService::LEAVE, $locked,
                old: ['status' => $revision->original['status']] + array_intersect_key($original, array_flip($changed)),
                new: ['status' => LeaveRequest::AWAITING_EMPLOYEE, 'revision_id' => $revision->id] + array_intersect_key($proposed, array_flip($changed)),
                reason: $reason, subjectEmployeeId: $locked->employee_id, actor: $hr);

            return $revision;
        });

        $fresh = $revision->fresh(['leaveRequest.leaveType', 'employee.user', 'proposer']);
        $fresh->employee?->user?->notify(new LeaveRevisionNotification($fresh, LeaveRevisionNotification::PROPOSED));

        return $fresh;
    }

    /**
     * The employee accepts: the proposed version becomes the request, which
     * goes back to HR for the final decision. Still nothing is deducted.
     */
    public function accept(LeaveRequestRevision $revision, User $employeeUser, ?string $note = null): LeaveRequest
    {
        $this->assertOwn($revision, $employeeUser);

        $request = DB::transaction(function () use ($revision, $employeeUser, $note) {
            [$locked, $leave] = $this->lockAwaiting($revision);

            // Checked again as it stands now: a balance or overlap may have changed.
            $input = $this->inputFrom($locked->proposed, $leave);
            $proposed = $this->proposal($leave, $input);
            $this->assertNoOverlap($leave, $proposed);
            $back = $this->statusBefore($locked);

            $this->apply($leave, $proposed, $back);
            $locked->update(['status' => LeaveRequestRevision::ACCEPTED, 'proposed' => $proposed, 'responded_by' => $employeeUser->id,
                'responded_at' => now(), 'response_note' => $note ? trim($note) : null]);

            app(AuditService::class)->event('LEAVE_CHANGE_ACCEPTED', AuditService::LEAVE, $leave,
                old: ['status' => LeaveRequest::AWAITING_EMPLOYEE] + array_intersect_key($locked->original, array_flip($locked->changed_fields)),
                new: ['status' => $back, 'revision_id' => $locked->id] + array_intersect_key($proposed, array_flip($locked->changed_fields)),
                reason: $note, subjectEmployeeId: $leave->employee_id, actor: $employeeUser);

            return $leave;
        });

        $this->notifyHr($revision->fresh(['leaveRequest.leaveType', 'employee.user', 'proposer']), LeaveRevisionNotification::ACCEPTED);

        return $request->fresh(['dayAllocations', 'leaveType']);
    }

    /** The employee rejects: the request stays exactly as submitted, back with HR. */
    public function reject(LeaveRequestRevision $revision, User $employeeUser, string $note): void
    {
        $this->assertOwn($revision, $employeeUser);
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new \DomainException('Tell HR why, in at least 5 characters.');
        }

        DB::transaction(function () use ($revision, $employeeUser, $note) {
            [$locked, $leave] = $this->lockAwaiting($revision);
            $back = $this->statusBefore($locked);

            $leave->update(['status' => $back]);
            $locked->update(['status' => LeaveRequestRevision::REJECTED, 'responded_by' => $employeeUser->id, 'responded_at' => now(), 'response_note' => $note]);

            app(AuditService::class)->event('LEAVE_CHANGE_REJECTED', AuditService::LEAVE, $leave,
                old: ['status' => LeaveRequest::AWAITING_EMPLOYEE], new: ['status' => $back, 'revision_id' => $locked->id],
                reason: $note, subjectEmployeeId: $leave->employee_id, actor: $employeeUser);
        });

        $this->notifyHr($revision->fresh(['leaveRequest.leaveType', 'employee.user', 'proposer']), LeaveRevisionNotification::REJECTED);
    }

    /** HR takes the proposal back before the employee answers: the request returns as submitted. */
    public function withdraw(LeaveRequestRevision $revision, User $hr, ?string $note = null): void
    {
        DB::transaction(function () use ($revision, $hr, $note) {
            [$locked, $leave] = $this->lockAwaiting($revision);
            if (! $this->isHrFor($leave, $hr)) {
                throw new AuthorizationException('You may not withdraw this proposal.');
            }
            $back = $this->statusBefore($locked);

            $leave->update(['status' => $back]);
            $locked->update(['status' => LeaveRequestRevision::WITHDRAWN, 'responded_by' => $hr->id, 'responded_at' => now(), 'response_note' => $note ? trim($note) : null]);

            app(AuditService::class)->event('LEAVE_CHANGE_WITHDRAWN', AuditService::LEAVE, $leave,
                old: ['status' => LeaveRequest::AWAITING_EMPLOYEE], new: ['status' => $back, 'revision_id' => $locked->id],
                reason: $note, subjectEmployeeId: $leave->employee_id, actor: $hr);
        });
    }

    /**
     * The LWP days an accepted revision agreed for this request, while its
     * dates and length are still the ones agreed. Approval keeps them: the
     * policy evaluation alone would read a paid split HR and the employee
     * agreed on as fully paid.
     */
    public function agreedLwpDays(LeaveRequest $request): float
    {
        $revision = LeaveRequestRevision::where('leave_request_id', $request->id)
            ->where('status', LeaveRequestRevision::ACCEPTED)->latest('id')->first();
        if ($revision === null) {
            return 0.0;
        }

        $agreed = $revision->proposed;
        $same = ($agreed['start_date'] ?? null) === Carbon::parse($request->start_date)->toDateString()
            && ($agreed['end_date'] ?? null) === Carbon::parse($request->end_date)->toDateString()
            && abs((float) ($agreed['days'] ?? -1) - (float) $request->days) < 0.001;

        return $same ? (float) ($agreed['lwp_days'] ?? 0) : 0.0;
    }

    // ── The versions ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function snapshot(LeaveRequest $request): array
    {
        $snapshot = [
            'start_date' => Carbon::parse($request->start_date)->toDateString(),
            'end_date' => Carbon::parse($request->end_date)->toDateString(),
            'is_half_day' => (bool) $request->is_half_day,
            'half_day_period' => $request->is_half_day ? $request->half_day_period : null,
            'leave_type_id' => (int) $request->leave_type_id,
            'days' => round((float) $request->days, 2),
            'lwp_days' => round((float) ($request->lwp_days ?? 0), 2),
            'requested_leave_status' => $request->requested_leave_status ?? 'paid',
            'balance_leave_type_id' => $request->balance_leave_type_id,
            'main_category' => $request->main_category,
            'approval_path' => $request->approval_path,
            'allocation' => null,
            'rows' => [],
        ];

        if ($request->isCombined()) {
            $rows = $request->dayAllocations()->orderBy('date')->get();
            $snapshot['rows'] = $rows->map(fn ($r) => [
                'date' => Carbon::parse($r->date)->toDateString(), 'status' => $r->day_status, 'allocation' => $r->allocation_type,
                'session' => $r->session, 'paid_units' => (float) $r->paid_units, 'unpaid_units' => (float) $r->unpaid_units,
                'chargeable' => ! $r->is_excluded, 'exclusion' => $r->exclusion_reason, 'label' => $r->metadata['label'] ?? null,
            ])->all();
            $snapshot['allocation'] = $this->allocationKey($snapshot['rows']);
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function proposal(LeaveRequest $request, array $input): array
    {
        $employee = $request->employee;
        $start = Carbon::parse($input['start_date'] ?? $request->start_date)->startOfDay();
        $end = Carbon::parse($input['end_date'] ?? $request->end_date)->startOfDay();
        if ($end->lt($start)) {
            throw new \DomainException('The end date is before the start date.');
        }
        $type = LeaveType::find($input['leave_type_id'] ?? $request->leave_type_id);
        if ($type === null) {
            throw new \DomainException('Choose a leave type.');
        }
        // Judged as the employee's own request was, on the day they sent it.
        $asOf = Carbon::parse($request->created_at ?? Carbon::today());

        if ($request->isCombined()) {
            return $this->combinedProposal($request, $type, $start, $end, $input, $asOf);
        }

        $isHalfDay = (bool) ($input['is_half_day'] ?? $request->is_half_day);
        $period = $isHalfDay ? ($input['half_day_period'] ?? $request->half_day_period) : null;
        if ($isHalfDay && ! $start->isSameDay($end)) {
            throw new \DomainException('A half-day leave is a single day — set the end date to the start date.');
        }
        if ($isHalfDay && ! in_array($period, ['first_half', 'second_half'], true)) {
            throw new \DomainException('Choose the first or second half for a half day.');
        }

        $evaluation = LeavePolicyEngine::enabled()
            ? app(LeaveRequestEvaluator::class)->evaluate($employee, $type, $start, $end, $isHalfDay, $request->id, $asOf)
            : null;
        if ($evaluation !== null) {
            // A shortfall is what the paid / unpaid split below is for.
            $errors = array_values(array_filter($evaluation['errors'], fn (string $e) => ! str_starts_with($e, 'You have only ')));
            if ($errors !== []) {
                throw new \DomainException($errors[0]);
            }
        }

        $days = $evaluation !== null
            ? (float) $evaluation['charged_days']
            : ($isHalfDay ? 0.5 : $this->leave->calculateLeaveDays($start, $end, (bool) $type->is_sandwich_applicable, (int) ($type->sandwich_min_days ?? 0)));
        if ($days <= 0) {
            throw new \DomainException('These dates contain no working days — there is no leave to charge.');
        }

        $unpaidType = ($evaluation['category'] ?? null) === LeaveCategory::UNPAID || ! $type->is_paid;
        $paidDays = match (true) {
            $unpaidType => 0.0,
            ($input['paid_days'] ?? '') !== '' && $input['paid_days'] !== null => round((float) $input['paid_days'], 2),
            $evaluation !== null => (float) $evaluation['paid_days'],
            default => $days,
        };
        if ($paidDays < 0 || $paidDays > $days) {
            throw new \DomainException("Paid days must be between 0 and {$days}.");
        }
        if (fmod($paidDays * 2, 1.0) !== 0.0) {
            throw new \DomainException('Paid days are whole or half days.');
        }
        $available = $evaluation['available'] ?? null;
        if ($paidDays > 0 && ! $unpaidType && $available !== null && ! ($evaluation['unlimited'] ?? false) && $paidDays > (float) $available + 0.001) {
            throw new \DomainException(sprintf('Only %s day(s) of %s are available — propose fewer paid days.', max(0, (float) $available) + 0, $evaluation['balance_type'] ?? $type->name));
        }

        $lwp = $unpaidType ? 0.0 : round($days - $paidDays, 2);

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'is_half_day' => $isHalfDay,
            'half_day_period' => $period,
            'leave_type_id' => (int) $type->id,
            'days' => round($days, 2),
            'lwp_days' => $lwp,
            'requested_leave_status' => $unpaidType || $paidDays <= 0 ? 'unpaid' : 'paid',
            'balance_leave_type_id' => $evaluation['balance_leave_type_id'] ?? ((int) $type->id === (int) $request->leave_type_id ? $request->balance_leave_type_id : null),
            'main_category' => $evaluation['category'] ?? $request->main_category,
            'approval_path' => $evaluation['approval_steps'] ?? $request->approval_path,
            'allocation' => null,
            'rows' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function combinedProposal(LeaveRequest $request, LeaveType $reason, CarbonInterface $start, CarbonInterface $end, array $input, CarbonInterface $asOf): array
    {
        $allocations = $input['allocations'] ?? $request->dayAllocations()->get()
            ->mapWithKeys(fn ($r) => [Carbon::parse($r->date)->toDateString() => ['type' => $r->allocation_type, 'session' => $r->session]])->all();

        $evaluation = app(CombinedLeaveService::class)->evaluate($request->employee, $reason, $start, $end, $allocations, $request->id, $asOf);
        if ($evaluation['blocked']) {
            throw new \DomainException($evaluation['errors'][0]);
        }

        $rows = array_map(fn (array $r) => [
            'date' => $r['date'], 'status' => $r['status'], 'allocation' => $r['allocation'], 'session' => $r['session'],
            'paid_units' => (float) $r['paid_units'], 'unpaid_units' => (float) $r['unpaid_units'],
            'chargeable' => (bool) $r['chargeable'], 'exclusion' => $r['exclusion'] ?? null, 'label' => $r['label'] ?? null,
        ], $evaluation['rows']);

        return [
            'start_date' => Carbon::parse($start)->toDateString(),
            'end_date' => Carbon::parse($end)->toDateString(),
            'is_half_day' => false,
            'half_day_period' => null,
            'leave_type_id' => (int) $reason->id,
            'days' => round((float) $evaluation['charged_units'], 2),
            'lwp_days' => round((float) $evaluation['unpaid_units'], 2),
            'requested_leave_status' => $evaluation['paid_units'] > 0 ? 'paid' : 'unpaid',
            'balance_leave_type_id' => $evaluation['balance_leave_type_id'],
            'main_category' => $evaluation['category'],
            'approval_path' => $evaluation['approval_steps'],
            'allocation' => $this->allocationKey($rows),
            'rows' => $rows,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function allocationKey(array $rows): string
    {
        return collect($rows)->filter(fn ($r) => $r['chargeable'])
            ->map(fn ($r) => $r['date'].'='.$r['allocation'].($r['session'] ? ':'.$r['session'] : ''))->implode(',');
    }

    /** @return array<int, string> */
    private function changed(array $original, array $proposed): array
    {
        return array_values(array_filter(self::MATERIAL, fn (string $field) => $field === 'days' || $field === 'lwp_days'
            ? abs((float) ($original[$field] ?? 0) - (float) ($proposed[$field] ?? 0)) > 0.001
            : ($original[$field] ?? null) != ($proposed[$field] ?? null)));
    }

    /** The proposal as input again, to re-check it at acceptance. */
    private function inputFrom(array $proposed, LeaveRequest $request): array
    {
        $input = [
            'start_date' => $proposed['start_date'], 'end_date' => $proposed['end_date'],
            'is_half_day' => $proposed['is_half_day'], 'half_day_period' => $proposed['half_day_period'],
            'leave_type_id' => $proposed['leave_type_id'],
            'paid_days' => round((float) $proposed['days'] - (float) $proposed['lwp_days'], 2),
        ];
        if ($request->isCombined()) {
            $input['allocations'] = collect($proposed['rows'])->filter(fn ($r) => $r['chargeable'])
                ->mapWithKeys(fn ($r) => [$r['date'] => ['type' => $r['allocation'], 'session' => $r['session']]])->all();
        }

        return $input;
    }

    private function apply(LeaveRequest $leave, array $proposed, string $status): void
    {
        $leave->update([
            'status' => $status,
            'start_date' => $proposed['start_date'],
            'end_date' => $proposed['end_date'],
            'is_half_day' => $proposed['is_half_day'],
            'half_day_period' => $proposed['half_day_period'],
            'leave_type_id' => $proposed['leave_type_id'],
            'days' => $proposed['days'],
            'lwp_days' => $proposed['lwp_days'] > 0 ? $proposed['lwp_days'] : null,
            'requested_leave_status' => $proposed['requested_leave_status'],
            'balance_leave_type_id' => $proposed['balance_leave_type_id'],
            'main_category' => $proposed['main_category'],
            'approval_path' => $proposed['approval_path'],
        ]);

        if ($leave->isCombined()) {
            // The submitted rows live on in the revision's original version.
            $leave->dayAllocations()->delete();
            foreach ($proposed['rows'] as $row) {
                $leave->dayAllocations()->create([
                    'date' => $row['date'],
                    'day_status' => $row['status'],
                    'allocation_type' => $row['allocation'],
                    'paid_units' => $row['paid_units'],
                    'unpaid_units' => $row['unpaid_units'],
                    'session' => $row['session'],
                    'is_excluded' => ! $row['chargeable'],
                    'exclusion_reason' => $row['exclusion'],
                    'balance_leave_type_id' => $row['paid_units'] > 0 ? $proposed['balance_leave_type_id'] : null,
                    'metadata' => $row['chargeable'] ? null : ['label' => $row['label']],
                ]);
            }
        }
    }

    private function assertNoOverlap(LeaveRequest $leave, array $proposed): void
    {
        $overlap = LeaveRequest::where('employee_id', $leave->employee_id)
            ->where('id', '!=', $leave->id)
            ->whereIn('status', array_merge(['approved'], LeaveBalanceCalculator::RESERVING_STATUSES))
            ->where('start_date', '<=', $proposed['end_date'])
            ->where('end_date', '>=', $proposed['start_date'])
            ->first();

        if ($overlap) {
            throw new \DomainException("These dates overlap another leave request ({$overlap->start_date->format('d M Y')} – {$overlap->end_date->format('d M Y')}).");
        }
    }

    /** @return array{0: LeaveRequestRevision, 1: LeaveRequest} */
    private function lockAwaiting(LeaveRequestRevision $revision): array
    {
        $leave = LeaveRequest::lockForUpdate()->findOrFail($revision->leave_request_id);
        $locked = LeaveRequestRevision::lockForUpdate()->findOrFail($revision->id);
        if (! $locked->isAwaiting() || $leave->status !== LeaveRequest::AWAITING_EMPLOYEE) {
            throw new \DomainException('This proposal has already been answered.');
        }

        return [$locked, $leave];
    }

    /** Where the request goes back to: the HR stage it was at (a request waiting on information starts again). */
    private function statusBefore(LeaveRequestRevision $revision): string
    {
        return ($revision->original['status'] ?? 'pending') === 'pending_hr' ? 'pending_hr' : 'pending';
    }

    private function isHrFor(?LeaveRequest $request, User $user): bool
    {
        $employee = $request?->employee;

        return $employee !== null
            && ! $this->guard->isSelf($user, $employee)
            && $user->hasPermission('view_leave_management')
            && app(ScopeResolver::class)->covers($user, 'view_leave_management', $employee)
            && $this->guard->covers($user, $employee, 'approve_leave');
    }

    private function assertOwn(LeaveRequestRevision $revision, User $user): void
    {
        $employeeId = $user->employee?->id;
        if ($employeeId === null || (int) $revision->employee_id !== (int) $employeeId) {
            throw new AuthorizationException('Only the employee can answer a proposed change to their leave.');
        }
    }

    private function notifyHr(LeaveRequestRevision $revision, string $stage): void
    {
        $employee = $revision->employee;
        if ($employee === null) {
            return;
        }

        $recipients = app(NotificationRecipients::class)->hrCovering($employee)
            ->reject(fn (User $hr) => (int) $hr->id === (int) $employee->user_id);
        if ($revision->proposer && ! $recipients->contains('id', $revision->proposer->id)) {
            $recipients->push($revision->proposer);
        }
        $recipients->each(fn (User $hr) => $hr->notify(new LeaveRevisionNotification($revision, $stage)));
    }
}
