<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveCancellationNotification;
use App\Services\Audit\AuditService;
use App\Services\LeaveService;
use App\Services\Notifications\NotificationRecipients;
use App\Services\Security\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling leave from the employee's side.
 *
 * Leave that is still pending can be withdrawn by the employee at once —
 * nothing has been deducted. Leave that is APPROVED is never cancelled by
 * the employee: they send a cancellation request with a reason, the leave
 * stays approved (and its days deducted) and HR decides. HR approving it
 * cancels the leave through LeaveAdminActionService (the audited HR path
 * that returns the days); HR rejecting it leaves the leave as it was.
 *
 * Every step runs in a transaction, locks the leave request, and is audited.
 */
class LeaveCancellationService
{
    /** Not yet approved: the employee may withdraw these themselves (what LeaveService can cancel). */
    public const WITHDRAWABLE = ['pending', 'pending_hr', LeaveRequest::AWAITING_EMPLOYEE];

    public function __construct(
        private readonly LeaveService $leave,
        private readonly LeaveAdminActionService $admin,
    ) {}

    /** The employee withdraws their own request that has not been approved yet. */
    public function withdrawPending(LeaveRequest $request, User $employeeUser): void
    {
        $this->assertOwn($request, $employeeUser);

        DB::transaction(function () use ($request, $employeeUser) {
            $locked = LeaveRequest::lockForUpdate()->findOrFail($request->id);
            if ($locked->status === 'approved') {
                throw new \DomainException('This leave is already approved — send HR a cancellation request instead.');
            }
            if (! in_array($locked->status, self::WITHDRAWABLE, true)) {
                throw new \DomainException('Only a pending leave request can be withdrawn.');
            }

            $this->leave->cancelRequest($locked, 'Withdrawn by the employee before approval', $employeeUser);
        });
    }

    /** The employee asks HR to cancel approved leave. The leave is not touched. */
    public function request(LeaveRequest $request, User $employeeUser, string $reason): LeaveCancellationRequest
    {
        $this->assertOwn($request, $employeeUser);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \DomainException('Give a reason of at least 5 characters for HR.');
        }

        $cancellation = DB::transaction(function () use ($request, $employeeUser, $reason) {
            $locked = LeaveRequest::lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== 'approved') {
                throw new \DomainException('Only approved leave needs a cancellation request; a pending request can be withdrawn directly.');
            }
            if ($locked->pendingCancellation()->exists()) {
                throw new \DomainException('A cancellation request for this leave is already with HR.');
            }

            $cancellation = LeaveCancellationRequest::create([
                'leave_request_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'requested_by' => $employeeUser->id,
                'reason' => $reason,
                'status' => LeaveCancellationRequest::PENDING,
            ]);

            app(AuditService::class)->event('LEAVE_CANCELLATION_REQUESTED', AuditService::LEAVE, $locked,
                new: ['cancellation_request_id' => $cancellation->id, 'leave_status' => $locked->status],
                reason: $reason, subjectEmployeeId: $locked->employee_id, actor: $employeeUser);

            return $cancellation;
        });

        $this->notifyHr($cancellation->fresh(['leaveRequest.leaveType', 'employee.user']));

        return $cancellation;
    }

    /** The employee takes back their own cancellation request before HR decides. */
    public function withdrawRequest(LeaveCancellationRequest $cancellation, User $employeeUser): void
    {
        $this->assertOwn($cancellation->leaveRequest, $employeeUser);

        DB::transaction(function () use ($cancellation, $employeeUser) {
            $locked = LeaveCancellationRequest::lockForUpdate()->findOrFail($cancellation->id);
            if (! $locked->isPending()) {
                throw new \DomainException('HR has already decided this cancellation request.');
            }

            $locked->update(['status' => LeaveCancellationRequest::WITHDRAWN, 'decided_by' => $employeeUser->id, 'decided_at' => now()]);
            app(AuditService::class)->event('LEAVE_CANCELLATION_WITHDRAWN', AuditService::LEAVE, $locked->leaveRequest,
                new: ['cancellation_request_id' => $locked->id], subjectEmployeeId: $locked->employee_id, actor: $employeeUser);
        });
    }

    /** HR approves: the leave is cancelled (days returned) and the request closed — together or not at all. */
    public function approve(LeaveCancellationRequest $cancellation, User $hr, ?string $note = null): void
    {
        $this->assertHrMayDecide($cancellation, $hr);

        DB::transaction(function () use ($cancellation, $hr, $note) {
            $locked = LeaveCancellationRequest::lockForUpdate()->findOrFail($cancellation->id);
            $leave = LeaveRequest::lockForUpdate()->findOrFail($locked->leave_request_id);
            if (! $locked->isPending()) {
                throw new \DomainException('This cancellation request has already been decided.');
            }
            if ($leave->status !== 'approved') {
                throw new \DomainException('The leave is no longer approved, so there is nothing to cancel.');
            }

            $this->admin->cancelApproved($leave, $hr, 'Cancellation requested by the employee: '.$locked->reason.($note ? ' — HR: '.trim($note) : ''));

            $locked->update(['status' => LeaveCancellationRequest::APPROVED, 'decided_by' => $hr->id, 'decided_at' => now(), 'decision_note' => $note ? trim($note) : null]);
            app(AuditService::class)->event('LEAVE_CANCELLATION_APPROVED', AuditService::LEAVE, $leave,
                new: ['cancellation_request_id' => $locked->id, 'leave_status' => 'cancelled'],
                reason: $note ?: $locked->reason, subjectEmployeeId: $leave->employee_id, actor: $hr);
        });
        // The employee hears from the cancellation itself (LeaveService notifies them).
    }

    /** HR rejects: the leave stays approved; the employee is told why. */
    public function reject(LeaveCancellationRequest $cancellation, User $hr, string $note): void
    {
        $this->assertHrMayDecide($cancellation, $hr);
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new \DomainException('Give the employee a reason of at least 5 characters.');
        }

        DB::transaction(function () use ($cancellation, $hr, $note) {
            $locked = LeaveCancellationRequest::lockForUpdate()->findOrFail($cancellation->id);
            if (! $locked->isPending()) {
                throw new \DomainException('This cancellation request has already been decided.');
            }

            $locked->update(['status' => LeaveCancellationRequest::REJECTED, 'decided_by' => $hr->id, 'decided_at' => now(), 'decision_note' => $note]);
            app(AuditService::class)->event('LEAVE_CANCELLATION_REJECTED', AuditService::LEAVE, $locked->leaveRequest,
                new: ['cancellation_request_id' => $locked->id, 'leave_status' => 'approved'],
                reason: $note, subjectEmployeeId: $locked->employee_id, actor: $hr);
        });

        $fresh = $cancellation->fresh(['leaveRequest.leaveType', 'employee.user']);
        $fresh->employee?->user?->notify(new LeaveCancellationNotification($fresh, LeaveCancellationNotification::REJECTED));
    }

    /** HR (manage approved leave) in scope, never deciding about their own leave. */
    public function hrMayDecide(LeaveCancellationRequest $cancellation, User $hr): bool
    {
        $employee = $cancellation->employee ?? Employee::find($cancellation->employee_id);

        return $employee !== null
            && (int) $employee->user_id !== (int) $hr->id
            && $hr->hasPermission('manage_approved_leave')
            && app(ScopeResolver::class)->covers($hr, 'manage_approved_leave', $employee);
    }

    private function assertHrMayDecide(LeaveCancellationRequest $cancellation, User $hr): void
    {
        if (! $this->hrMayDecide($cancellation, $hr)) {
            throw new AuthorizationException('You may not decide this cancellation request.');
        }
    }

    private function assertOwn(LeaveRequest $request, User $user): void
    {
        $employeeId = $user->employee?->id;
        if ($employeeId === null || (int) $request->employee_id !== (int) $employeeId) {
            throw new AuthorizationException('Only the employee can cancel their own leave here.');
        }
    }

    private function notifyHr(LeaveCancellationRequest $cancellation): void
    {
        $employee = $cancellation->employee;
        if ($employee === null) {
            return;
        }

        app(NotificationRecipients::class)->hrCovering($employee)
            ->reject(fn (User $hr) => (int) $hr->id === (int) $employee->user_id)
            ->each(fn (User $hr) => $hr->notify(new LeaveCancellationNotification($cancellation, LeaveCancellationNotification::REQUESTED)));
    }
}
