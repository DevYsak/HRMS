<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Exceptions\ApprovalNotPermitted;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePaymentAuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Notifications\LeaveAppliedOnBehalfNotification;
use App\Notifications\LeaveEncashmentNotification;
use App\Notifications\LeaveMonthlyAccrualNotification;
use App\Notifications\LeavePaymentStatusChangedNotification;
use App\Notifications\LeaveRequestNotification;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Audit\AuditService;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCarryOverService;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveRuleResolver;
use App\Services\Leave\LeaveYearResolver;
use App\Services\Notifications\NotificationRecipients;
use App\Services\Teams\ApprovalRoutingService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    /**
     * Count leave days between two dates, applying sandwich policy when enabled.
     * Sandwich = weekends/holidays between leave days are counted as leave days.
     * When disabled (default), only working days are counted (but here we count
     * all calendar days for simplicity — sandwich flag determines whether to
     * also count the intervening weekend when leave splits across it).
     */
    public function calculateLeaveDays(Carbon $start, Carbon $end, bool $sandwichApplicable = false, int $sandwichMinDays = 0): float
    {
        $calendarDays = $start->diffInDays($end) + 1;

        // Sandwich counting (weekends within the range count as leave) applies
        // only when enabled AND the leave spans at least the configured minimum.
        // A minimum of 0 keeps the legacy "always sandwich when enabled" rule.
        if ($sandwichApplicable && $calendarDays >= max(1, $sandwichMinDays)) {
            return (float) $calendarDays;
        }

        // Without sandwich: count only scheduled working days — the weekly off
        // (Saturday + Sunday for Conexus) never consumes leave. Fri → Mon = 2.
        $total = 0.0;
        $cursor = $start->copy();
        $days = app(WorkingDayResolver::class);
        while ($cursor->lte($end)) {
            if (! $days->isWeeklyOff($cursor)) {
                $total++;
            }
            $cursor->addDay();
        }

        return $total;
    }

    /**
     * Cross-request sandwich bridge.
     *
     * When a leave type opts into the sandwich policy, a weekend that is
     * "trapped" between this request and an *existing* leave block (submitted
     * separately) should also be charged as leave — e.g. leave on Fri (one
     * request) and Mon (another) sandwiches the Sat/Sun in between.
     *
     * This resolves the effective [start, end] by pulling the boundary outward
     * to swallow a run of weekend days that sits directly between the requested
     * dates and an adjacent approved/pending block. The gap must be made up of
     * weekend days ONLY — a working day or a company holiday in the gap breaks
     * the bridge (holidays are handled by blocking, never counted here).
     *
     * Returns the original dates unchanged when the policy is off, no adjacent
     * block exists, or the bridged span falls short of the sandwich minimum.
     *
     * @return array{0: Carbon, 1: Carbon} the effective [start, end]
     */
    public function resolveSandwichBridge(
        Employee $employee,
        LeaveType $leaveType,
        Carbon $start,
        Carbon $end,
        ?int $excludeRequestId = null,
    ): array {
        $days = app(WorkingDayResolver::class);

        if (! $leaveType->is_sandwich_applicable || $days->isWeeklyOff($start) || $days->isWeeklyOff($end)) {
            return [$start->copy(), $end->copy()];
        }

        $bridgedStart = $start->copy();
        $bridgedEnd = $end->copy();

        // Backward: walk over the run of weekend days immediately before the
        // start; if a leave block sits on the working day just beyond it, the
        // weekend is trapped, so pull the start back to the first weekend day.
        $probe = $start->copy()->subDay();
        $weekendRun = 0;
        while ($days->isWeeklyOff($probe)) {
            $weekendRun++;
            $probe->subDay();
        }
        if ($weekendRun > 0 && $this->hasLeaveOn($employee->id, $probe, $excludeRequestId)) {
            $bridgedStart = $probe->copy()->addDay();
        }

        // Forward: mirror image after the end date.
        $probe = $end->copy()->addDay();
        $weekendRun = 0;
        while ($days->isWeeklyOff($probe)) {
            $weekendRun++;
            $probe->addDay();
        }
        if ($weekendRun > 0 && $this->hasLeaveOn($employee->id, $probe, $excludeRequestId)) {
            $bridgedEnd = $probe->copy()->subDay();
        }

        // Respect the sandwich minimum-span threshold — if the bridged span
        // doesn't reach it the weekend wouldn't be counted anyway, so leave the
        // dates untouched to keep stored dates and the day-count consistent.
        $span = $bridgedStart->diffInDays($bridgedEnd) + 1;
        if ($span < max(1, (int) $leaveType->sandwich_min_days)) {
            return [$start->copy(), $end->copy()];
        }

        return [$bridgedStart, $bridgedEnd];
    }

    /**
     * Whether the employee has an in-force leave request (approved or pending)
     * covering the given date. Used to detect an adjacent block for the
     * cross-request sandwich bridge.
     */
    private function hasLeaveOn(int $employeeId, Carbon $date, ?int $excludeRequestId = null): bool
    {
        return LeaveRequest::where('employee_id', $employeeId)
            ->when($excludeRequestId, fn ($q) => $q->where('id', '!=', $excludeRequestId))
            ->whereIn('status', ['approved', 'pending', 'pending_hr'])
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->exists();
    }

    /**
     * The first active holiday within [start, end] that applies to the given
     * employee — on their own holiday calendar (UK / IN) and within any
     * branch / department / employee scope — or null. Used to block leave
     * that overlaps a holiday. It ignored the calendar, so UK staff were
     * blocked over Indian holidays and India staff over UK bank holidays.
     */
    public function holidayWithinRange(Employee $employee, Carbon $start, Carbon $end): ?PublicHoliday
    {
        $resolver = app(HolidayResolver::class);

        return $resolver->holidaysInRange($start, $end)
            ->sortBy('date')
            ->first(fn (PublicHoliday $h) => $resolver->appliesTo($h, $employee));
    }

    /**
     * Submit a leave request on behalf of an employee.
     * Validates all leave-type policies before creating the request.
     */
    public function submitRequest(
        Employee $employee,
        LeaveType $leaveType,
        string $startDate,
        string $endDate,
        string $reason,
        bool $isHalfDay = false,
        ?string $halfDayPeriod = null,
        string $requestedLeaveStatus = 'paid',
        ?string $attachmentPath = null,
        ?string $employeeRemarks = null,
        array $attachments = [],
    ): LeaveRequest {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Back-compat: if the caller only passed the new multi-attachment
        // array, mirror its first file into attachment_path so the existing
        // attachment_required check and any single-attachment consumer
        // (reports, the AllTimeOff/TeamTimeOff legacy link) keep working.
        if ($attachmentPath === null && $attachments !== []) {
            $attachmentPath = $attachments[0]['path'] ?? null;
        }

        // ── Policy Validations ────────────────────────────────────────────────

        // The employee's policy rule for this type, falling back to the
        // type's own settings where the rule says nothing.
        $rules = app(LeaveRuleResolver::class)->settings($employee, $leaveType);

        // Half-day period required when half day selected
        if ($isHalfDay && $halfDayPeriod === null) {
            throw new \DomainException('Please specify first half or second half for the half-day leave.');
        }

        // Half day allowed by leave type
        if ($isHalfDay && ! $rules['allow_half_day']) {
            throw new \DomainException("'{$leaveType->name}' does not allow half-day requests.");
        }

        // Paid/Unpaid eligibility
        if ($requestedLeaveStatus === 'paid' && ! $leaveType->allow_paid_request) {
            throw new \DomainException("'{$leaveType->name}' can only be requested as unpaid leave.");
        }
        if ($requestedLeaveStatus === 'unpaid' && ! $leaveType->allow_unpaid_request) {
            throw new \DomainException("'{$leaveType->name}' can only be requested as paid leave.");
        }

        // Gender restriction
        if (! $leaveType->isGenderEligible($employee->gender)) {
            throw new \DomainException("'{$leaveType->name}' is not available for your gender.");
        }

        // Probation restriction
        if ($rules['probation_restricted'] && $employee->status === EmployeeStatus::Probation) {
            throw new \DomainException("'{$leaveType->name}' is not available during probation.");
        }

        // Notice period restriction
        if ($rules['notice_period_restricted'] && $employee->status === EmployeeStatus::NoticePeriod) {
            throw new \DomainException("'{$leaveType->name}' is not available during notice period.");
        }

        // Weekly-off block — a non-working day can't be picked as the start or
        // end of leave. Offs that merely fall inside a longer range (e.g. a
        // Fri→Mon leave) are fine — they're simply not counted.
        //
        // Uses the configured week, not Carbon's isWeekend(). Under a
        // Sunday-only week that hardcoded Sat+Sun and refused leave starting on
        // a Saturday the company actually works.
        if (app(WorkingDayResolver::class)->isWeeklyOff($start)) {
            throw new \DomainException($start->format('l, d M Y').' is a non-working day — please pick a working day as your start date.');
        }
        if (app(WorkingDayResolver::class)->isWeeklyOff($end)) {
            throw new \DomainException($end->format('l, d M Y').' is a non-working day — please pick a working day as your end date.');
        }

        // Company holiday block — leave cannot include a holiday that applies
        // to this employee (branch/department/employee scope respected).
        $holiday = $this->holidayWithinRange($employee, $start, $end);
        if ($holiday) {
            throw new \DomainException(
                Carbon::parse($holiday->date)->format('d M Y')." ({$holiday->name}) is already a company holiday. Please exclude it from your leave dates."
            );
        }

        // Mandatory December Leave — the company is shut on these dates. They
        // are not drawn from CSL or any other balance, so no leave can be
        // applied on them; working one goes through attendance (Comp Off).
        $shutdown = DecemberMandatoryDay::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->first();
        if ($shutdown) {
            throw new \DomainException(
                $shutdown->date->format('d M Y').' is a Mandatory December Leave (company shutdown) day — it is not charged to your leave. Please exclude it from your leave dates.'
            );
        }

        // Cross-request sandwich bridge — pull the boundary outward to swallow
        // a weekend trapped between this request and an adjacent leave block
        // (e.g. leave on Fri as one request + Mon as another). No-op for
        // half-days and when the sandwich policy is off. Runs after the weekend
        // and holiday validations so only the internal effective dates change.
        if (! $isHalfDay) {
            [$start, $end] = $this->resolveSandwichBridge($employee, $leaveType, $start, $end);
            $startDate = $start->toDateString();
            $endDate = $end->toDateString();
        }

        // Max consecutive days
        $days = $isHalfDay ? 0.5 : $this->calculateLeaveDays($start, $end, (bool) $leaveType->is_sandwich_applicable, (int) $leaveType->sandwich_min_days);

        if ($rules['max_consecutive_days'] !== null && $days > $rules['max_consecutive_days']) {
            throw new \DomainException(
                "'{$leaveType->name}' allows a maximum of {$rules['max_consecutive_days']} consecutive day(s)."
            );
        }

        // Attachment required
        if ($rules['attachment_required'] && $attachmentPath === null) {
            throw new \DomainException("An attachment is required for '{$leaveType->name}'.");
        }

        // Overlap check — must run before balance check so conflict errors surface first.
        // A request waiting on the employee's reply still holds its dates.
        $overlap = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'pending', 'pending_hr', 'more_info_requested'])
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->first();

        if ($overlap) {
            $s = $overlap->status === 'approved' ? 'approved' : 'pending';
            throw new \DomainException(
                "You already have a {$s} leave from {$overlap->start_date->format('d M Y')} to {$overlap->end_date->format('d M Y')}."
            );
        }

        // Balance check — only for paid requests (after overlap so conflict errors surface first)
        if ($requestedLeaveStatus === 'paid') {
            // The leave year the leave falls in, and net of the days other
            // pending requests already reserve — two requests must not each
            // be accepted against the same available days.
            $balance = $this->balanceForDate($employee->id, $leaveType->id, Carbon::parse($startDate));
            $available = $balance
                ? max(0, app(LeaveBalanceCalculator::class)->summary($balance)['available_to_request'])
                : 0;

            if ($available < $days) {
                throw new \DomainException(
                    "Insufficient balance. Available: {$available} day(s), requested: {$days}. You may request as unpaid leave."
                );
            }
        }

        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_half_day' => $isHalfDay,
            'half_day_period' => $isHalfDay ? $halfDayPeriod : null,
            'days' => $days,
            'reason' => $reason,
            'requested_leave_status' => $requestedLeaveStatus,
            'approved_leave_status' => null,
            'attachment_path' => $attachmentPath,
            'status' => 'pending',
        ]);

        foreach ($attachments as $file) {
            if (empty($file['path'])) {
                continue;
            }
            $request->attachments()->create([
                'type' => $file['type'] ?? 'supporting_document',
                'path' => $file['path'],
                'original_name' => $file['original_name'] ?? basename($file['path']),
                'mime_type' => $file['mime_type'] ?? null,
                'size' => $file['size'] ?? 0,
            ]);
        }

        // Notify approvers — reporting manager + dept head + all in-scope HR,
        // plus the team lead/backup chain (v4 Part 3.2) when the employee is on
        // an active team. Deduplicated by user id so nobody is pinged twice.
        $request->load(['employee.user', 'leaveType']);

        $recipients = collect();
        $push = function (?User $u) use ($recipients) {
            if ($u && ! $recipients->contains(fn (User $r) => $r->id === $u->id)) {
                $recipients->push($u);
            }
        };

        $push($employee->manager);
        $push($employee->department?->head);

        // Team lead / secondary lead resolved for the leave start date.
        app(ApprovalRoutingService::class)
            ->getApproverChain($employee, Carbon::parse($startDate))
            ->each($push);

        // The HR queue as well as the approver chain, minus anyone the chain
        // already covered. Deliberate: HR tracks every request centrally.
        $notifiedIds = $recipients->pluck('id')->all();
        app(NotificationRecipients::class)->hrQueue()
            ->reject(fn (User $u) => \in_array($u->id, $notifiedIds, true))
            ->each($push);

        // Manager, approver chain and HR each tagged with the role they are
        // being notified as, so all three can be configured separately.
        $managerId = $employee->manager_id;
        $recipients->each(fn (User $u) => $u->notify(
            (new LeaveRequestNotification($request))->forRole(match (true) {
                $managerId !== null && $u->id === $managerId => 'manager',
                \in_array($u->role?->value ?? $u->role, ['hr_admin', 'super_admin'], true) => 'hr_admin',
                default => 'approver',
            })
        ));

        return $request;
    }

    /**
     * HR submits a leave request for another employee.
     *
     * Exactly the employee's own path — every policy, overlap and balance
     * check of submitRequest() applies — with the submitting HR user and an
     * internal note recorded on the request, and an audit entry that names
     * both the actor (HR) and the subject (the employee). The request then
     * goes through the normal approval chain.
     *
     * @param  array<int, array{path: string, name?: string|null, mime?: string|null, size?: int|null}>  $attachments
     */
    public function applyOnBehalf(
        User $hr,
        Employee $employee,
        LeaveType $leaveType,
        string $startDate,
        string $endDate,
        string $reason,
        bool $isHalfDay = false,
        ?string $halfDayPeriod = null,
        string $requestedLeaveStatus = 'paid',
        ?string $internalNote = null,
        bool $notifyEmployee = true,
        array $attachments = [],
    ): LeaveRequest {
        if (! $hr->hasPermission('apply_leave_on_behalf')) {
            throw new AuthorizationException('You may not apply leave on behalf of another employee.');
        }

        if ($hr->employee?->id === $employee->id) {
            throw new \DomainException('Use My Time Off to apply for your own leave.');
        }

        return DB::transaction(function () use ($hr, $employee, $leaveType, $startDate, $endDate, $reason, $isHalfDay, $halfDayPeriod, $requestedLeaveStatus, $internalNote, $notifyEmployee, $attachments) {
            $request = $this->submitRequest(
                $employee, $leaveType, $startDate, $endDate, $reason, $isHalfDay, $halfDayPeriod,
                $requestedLeaveStatus, attachments: $attachments,
            );

            $request->forceFill(['applied_by_user_id' => $hr->id, 'hr_internal_note' => $internalNote ?: null])->save();

            app(AuditService::class)->event('LEAVE_APPLIED_ON_BEHALF', AuditService::LEAVE, $request,
                new: [
                    'actor_user_id' => $hr->id,
                    'subject_employee_id' => $employee->id,
                    'leave_type' => $leaveType->name,
                    'start_date' => $request->start_date->toDateString(),
                    'end_date' => $request->end_date->toDateString(),
                    'days' => (float) $request->days,
                    'half_day' => $isHalfDay ? $halfDayPeriod : null,
                    'requested_leave_status' => $requestedLeaveStatus,
                    'notify_employee' => $notifyEmployee,
                ],
                reason: $reason, subjectEmployeeId: $employee->id);

            if ($notifyEmployee && $employee->user) {
                $employee->user->notify(new LeaveAppliedOnBehalfNotification($request->fresh('leaveType'), $hr->name));
            }

            return $request;
        });
    }

    /**
     * Review a leave request. The reporting approver (manager, department
     * head) or HR decides, and the decision is final (D2).
     */
    public function reviewRequest(
        LeaveRequest $leaveRequest,
        array $data,
        string $status,
        int $reviewerId,
        ?string $comment = null,
    ): LeaveRequest {
        $this->assertCanReview($leaveRequest, $reviewerId);

        return DB::transaction(function () use ($leaveRequest, $data, $status, $reviewerId, $comment) {
            $oldStatus = $leaveRequest->status;
            $oldDays = (float) $leaveRequest->days;
            $oldTypeId = $leaveRequest->leave_type_id;
            $oldStart = Carbon::parse($leaveRequest->start_date);
            $employee = $leaveRequest->employee;

            $start = Carbon::parse($data['start_date']);
            $end = Carbon::parse($data['end_date']);
            $isHalfDay = (bool) ($data['is_half_day'] ?? false);
            $leaveTypeFresh = LeaveType::find($data['leave_type_id']);

            // Re-apply the cross-request sandwich bridge on the (possibly edited)
            // dates, excluding this request itself so it never bridges to its own
            // old range. Keeps stored dates and the day-count consistent.
            if (! $isHalfDay && $leaveTypeFresh) {
                [$start, $end] = $this->resolveSandwichBridge($employee, $leaveTypeFresh, $start, $end, $leaveRequest->id);
                $data['start_date'] = $start->toDateString();
                $data['end_date'] = $end->toDateString();
            }

            $newDays = $isHalfDay ? 0.5 : $this->calculateLeaveDays(
                $start, $end,
                (bool) ($leaveTypeFresh?->is_sandwich_applicable ?? false),
                (int) ($leaveTypeFresh?->sandwich_min_days ?? 0)
            );

            // Reverse any prior balance deduction if it was approved+paid
            if ($oldStatus === 'approved' && $this->wasApprovedAsPaid($leaveRequest)) {
                app(LeaveMovementService::class)->reverseUsage($leaveRequest, (int) $oldTypeId, $oldDays, $oldStart, 'Leave re-reviewed', User::find($reviewerId));
            }

            $reviewer = User::find($reviewerId);
            // D2 (8 Oct 2026): the reporting approver's decision is final —
            // there is no routine second HR approval. HR keeps override,
            // correction and escalation. Requests already parked in
            // pending_hr are still finished through hrApproval().
            $resolvedStatus = $status;

            // An approval must take at least one working day (an edited range
            // can land entirely on non-working days); nothing to approve or debit.
            if ($resolvedStatus === 'approved' && $newDays <= 0) {
                throw new \DomainException('These dates contain no working days — there is no leave to approve.');
            }

            $leaveRequest->update([
                'status' => $resolvedStatus,
                'leave_type_id' => $data['leave_type_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_half_day' => $isHalfDay,
                'days' => $newDays,
                'reason' => $data['reason'],
                'reviewer_id' => $reviewerId,
                'reviewer_comment' => $comment,
            ]);

            if ($resolvedStatus === 'approved') {
                $leaveRequest->update(['approved_at' => now()]);
                $this->checkOverlapAndDeductBalance($leaveRequest, $employee, $data, $newDays);
            }

            $this->auditDecision($leaveRequest, $oldStatus, $resolvedStatus, [
                'leave_type_id' => $oldTypeId, 'start_date' => $oldStart->toDateString(), 'days' => $oldDays,
            ], $comment, $reviewer);

            $fresh = $leaveRequest->fresh(['employee.user', 'leaveType', 'reviewer']);
            $fresh->employee->user->notify((new LeaveRequestNotification($fresh))->forRole('employee'));

            if ($resolvedStatus === 'pending_hr') {
                // Routed to HR as a queue — whoever picks it up first acts.
                app(NotificationRecipients::class)->hrQueue()
                    ->each(fn (User $hr) => $hr->notify((new LeaveRequestNotification($fresh))->forRole('hr_admin')));
            }

            return $fresh;
        });
    }

    /**
     * Manager/HR asks the employee for more information instead of deciding
     * outright — opens the conversation with the reviewer's message. Balances
     * are untouched (nothing was ever deducted). The employee replies via
     * postMessage(), which returns it to 'pending' for a fresh review.
     */
    public function requestMoreInfo(LeaveRequest $leaveRequest, int $reviewerId, string $comment, ?string $attachmentPath = null, ?string $attachmentName = null): LeaveRequest
    {
        if (! in_array($leaveRequest->status, ['pending', 'pending_hr'], true)) {
            throw new \DomainException('Only a pending leave request can have more information requested.');
        }

        app(ApprovalGuard::class)->assertCanDecide($reviewerId, $leaveRequest->employee);

        $reviewer = User::findOrFail($reviewerId);

        return $this->postMessage($leaveRequest, $reviewer, $comment, $attachmentPath, $attachmentName);
    }

    /**
     * Post a message to a leave request's conversation thread and move the
     * request along the clarification loop:
     *   - a reviewer posting on a pending/pending_hr request  → more_info_requested
     *   - the employee replying on a more_info_requested one  → back to pending
     *   - anyone posting on an already-decided request         → message only
     * Notifies the other party. Either the body or an attachment must be present.
     */
    public function postMessage(LeaveRequest $leaveRequest, User $sender, ?string $body, ?string $attachmentPath = null, ?string $attachmentName = null): LeaveRequest
    {
        $body = $body !== null ? trim($body) : null;
        if (($body === null || $body === '') && $attachmentPath === null) {
            throw new \DomainException('Add a message or an attachment before sending.');
        }

        $leaveRequest->messages()->create([
            'user_id' => $sender->id,
            'body' => $body ?: null,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
        ]);

        $employeeUserId = $leaveRequest->employee?->user_id;
        $isEmployee = $sender->id === $employeeUserId;

        if (! $isEmployee && in_array($leaveRequest->status, ['pending', 'pending_hr'], true)) {
            // Reviewer is asking for clarification.
            $leaveRequest->update([
                'status' => 'more_info_requested',
                'reviewer_id' => $sender->id,
                'reviewer_comment' => $body ?: $leaveRequest->reviewer_comment,
            ]);
        } elseif ($isEmployee && $leaveRequest->status === 'more_info_requested') {
            // Employee has responded — send it back for a fresh review.
            $leaveRequest->update(['status' => 'pending']);
        }

        $fresh = $leaveRequest->fresh(['employee.user', 'leaveType']);

        // Notify the other side.
        if ($isEmployee) {
            $manager = $fresh->employee->manager;
            $manager?->notify((new LeaveRequestNotification($fresh))->forRole('manager'));
            app(NotificationRecipients::class)->hrQueue()
                ->reject(fn (User $hr) => $manager && $hr->id === $manager->id)
                ->each(fn (User $hr) => $hr->notify((new LeaveRequestNotification($fresh))->forRole('hr_admin')));
        } else {
            $fresh->employee->user?->notify((new LeaveRequestNotification($fresh))->forRole('employee'));
        }

        return $fresh;
    }

    /**
     * HR decision on a request still parked in pending_hr. New approvals no
     * longer go there (D2); this finishes the ones that already did.
     */
    public function hrApproveRequest(
        LeaveRequest $leaveRequest,
        int $hrReviewerId,
        string $decision,
        ?string $comment = null,
        ?string $approvedLeaveStatus = null,
        ?string $hrRemark = null,
    ): LeaveRequest {
        if ($leaveRequest->status !== 'pending_hr') {
            throw new \DomainException('This leave request is not awaiting HR approval.');
        }

        return DB::transaction(function () use ($leaveRequest, $hrReviewerId, $decision, $comment, $approvedLeaveStatus, $hrRemark) {
            $employee = $leaveRequest->employee;
            $newDays = (float) $leaveRequest->days;

            // Determine effective approved payment status
            $effectiveStatus = $approvedLeaveStatus ?? $leaveRequest->requested_leave_status ?? 'paid';

            $updateData = [
                'status' => $decision,
                'hr_reviewer_id' => $hrReviewerId,
                'hr_reviewer_comment' => $comment,
                'hr_reviewed_at' => now(),
                'approved_leave_status' => $effectiveStatus,
            ];

            if ($decision === 'approved') {
                $updateData['approved_at'] = now();
            }

            // Write payment audit log when HR overrides from requested status
            if ($approvedLeaveStatus && $approvedLeaveStatus !== $leaveRequest->requested_leave_status) {
                $this->writePaymentAuditLog(
                    $leaveRequest,
                    $hrReviewerId,
                    $leaveRequest->requested_leave_status ?? 'paid',
                    $approvedLeaveStatus,
                    $hrRemark ?? $comment ?? '',
                    'approval'
                );

                $updateData['payment_status_changed_by'] = $hrReviewerId;
                $updateData['payment_status_changed_at'] = now();
                $updateData['payment_status_change_reason'] = $hrRemark ?? $comment;
                $updateData['hr_remark'] = $hrRemark;
            }

            $oldStatus = $leaveRequest->status;
            $leaveRequest->update($updateData);
            $this->auditDecision($leaveRequest, $oldStatus, $decision, [], $comment, User::find($hrReviewerId));

            // Posted through the ledger, in the leave year of the leave itself.
            // A direct increment('used_days') is refused on a ledger-backed
            // balance and skipped the usage entry entirely.
            if ($decision === 'approved' && $effectiveStatus === 'paid') {
                app(LeaveMovementService::class)->recordUsage(
                    $leaveRequest, $newDays, (int) $leaveRequest->leave_type_id,
                    Carbon::parse($leaveRequest->start_date), enforceBalance: false, actor: User::find($hrReviewerId),
                );
            }

            $fresh = $leaveRequest->fresh(['employee.user', 'leaveType', 'reviewer', 'hrReviewer']);
            $fresh->employee->user->notify((new LeaveRequestNotification($fresh))->forRole('employee'));

            return $fresh;
        });
    }

    /**
     * HR override of paid/unpaid status on an already-approved or pending_hr request.
     * Writes immutable audit log; remark is always mandatory.
     */
    public function hrOverridePaymentStatus(
        LeaveRequest $leaveRequest,
        User $hr,
        string $newStatus,
        string $remark,
    ): void {
        if (! \in_array($hr->role?->value ?? $hr->role, ['hr_admin', 'super_admin'], true)) {
            abort(403, 'Only HR Admins or Super Admins can override payment status.');
        }

        app(ApprovalGuard::class)->assertCanDecide($hr, $leaveRequest->employee);

        $leaveType = $leaveRequest->leaveType;

        if (! $leaveType?->allow_hr_override) {
            throw new \DomainException("'{$leaveType?->name}' does not allow HR payment status override.");
        }

        if (trim($remark) === '') {
            throw new \DomainException('HR remark is mandatory when changing the payment status.');
        }

        $currentStatus = $leaveRequest->effectiveLeaveStatus();

        if ($currentStatus === $newStatus) {
            throw new \DomainException('The leave is already set to '.$newStatus.'.');
        }

        $employee = $leaveRequest->employee;
        $days = (float) $leaveRequest->days;

        DB::transaction(function () use ($leaveRequest, $hr, $newStatus, $remark, $currentStatus, $employee, $days) {
            // Reverse / apply balance changes
            if ($leaveRequest->status === 'approved') {
                $movements = app(LeaveMovementService::class);
                $start = Carbon::parse($leaveRequest->start_date);

                if ($currentStatus === 'paid' && $newStatus === 'unpaid') {
                    // Return the days to the leave year the leave was taken in.
                    $movements->reverseUsage($leaveRequest, (int) $leaveRequest->leave_type_id, $days, $start, 'Changed to unpaid by HR', $hr);
                } elseif ($currentStatus === 'unpaid' && $newStatus === 'paid') {
                    $balance = $movements->balanceFor($employee->id, (int) $leaveRequest->leave_type_id, $start);
                    $available = max(0, $movements->approvedAvailable($balance));

                    if ($available < $days) {
                        throw new \DomainException(
                            "Insufficient balance to convert to paid leave. Available: {$available} day(s), required: {$days}."
                        );
                    }

                    $movements->recordUsage($leaveRequest, $days, (int) $leaveRequest->leave_type_id, $start, enforceBalance: false, actor: $hr);
                }
            }

            $stage = $leaveRequest->status === 'approved' ? 'post_approval' : 'approval';

            $leaveRequest->update([
                'approved_leave_status' => $newStatus,
                'payment_status_changed_by' => $hr->id,
                'payment_status_changed_at' => now(),
                'payment_status_change_reason' => $remark,
                'hr_remark' => $remark,
            ]);

            $this->writePaymentAuditLog($leaveRequest, $hr->id, $currentStatus, $newStatus, $remark, $stage);

            // Notify employee
            $leaveRequest->employee->user->notify(
                new LeavePaymentStatusChangedNotification($leaveRequest->fresh(), $currentStatus, $newStatus, $hr)
            );
        });
    }

    /**
     * Monthly accrual — credits leave days for all active employees × accrual-eligible types.
     * Safe to call multiple times; skips already-accrued employee+type+month combinations.
     *
     * @return int Number of accrual entries processed
     */
    public function accrueMonthly(int $year, int $month): int
    {
        // Rule-driven (policy rule, falling back to the leave type), credited
        // as its own ACCRUAL lot in the month's leave year; never resets any
        // other figure. Idempotent per employee, type and month.
        $result = app(LeaveAccrualService::class)->run($year, $month);
        $count = $result['credited'];

        // A monthly summary for HR as a team, not for any one administrator.
        if ($count > 0) {
            app(NotificationRecipients::class)->hrQueue()
                ->each(fn (User $hr) => $hr->notify(new LeaveMonthlyAccrualNotification($count, $year, $month)));
        }

        return $count;
    }

    /**
     * Save (create or update) a leave type including all enterprise fields.
     */
    public function saveLeaveType(array $data, ?int $id = null): LeaveType
    {
        return LeaveType::updateOrCreate(
            ['id' => $id],
            [
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'is_paid' => $data['is_paid'],
                'allow_paid_request' => $data['allow_paid_request'] ?? true,
                'allow_unpaid_request' => $data['allow_unpaid_request'] ?? true,
                'allow_hr_override' => $data['allow_hr_override'] ?? true,
                'hr_remark_required' => $data['hr_remark_required'] ?? true,
                'color' => $data['color'],
                'category' => $data['category'],
                'allow_carry_forward' => $data['allow_carry_forward'],
                // The mode is what the engine consults; without it here the
                // settings screen would collect a choice that never persisted.
                'carry_forward_mode' => $data['carry_forward_mode'] ?? LeaveType::CARRY_HR_APPROVAL,
                'carry_forward_limit' => $data['carry_forward_limit'],
                'allow_encashment' => $data['allow_encashment'],
                'max_encashable_days' => $data['max_encashable_days'] ?? null,
                'encashment_rate_multiplier' => $data['encashment_rate_multiplier'] ?? 1.00,
                'allow_current_year_encashment' => $data['allow_current_year_encashment'] ?? false,
                'is_sandwich_applicable' => $data['is_sandwich_applicable'] ?? false,
                'sandwich_min_days' => $data['sandwich_min_days'] ?? 0,
                'allow_half_day' => $data['allow_half_day'] ?? true,
                'is_monthly_accrual' => $data['is_monthly_accrual'] ?? false,
                'accrual_days_per_month' => $data['accrual_days_per_month'] ?? 0,
                'gender_restriction' => $data['gender_restriction'] ?? 'none',
                'probation_restricted' => $data['probation_restricted'] ?? false,
                'notice_period_restricted' => $data['notice_period_restricted'] ?? false,
                'max_consecutive_days' => $data['max_consecutive_days'] ?: null,
                'attachment_required' => $data['attachment_required'] ?? false,
                'annual_allocation_days' => $data['annual_allocation_days'] ?: null,
                'is_system_controlled' => $data['is_system_controlled'] ?? false,
            ],
        );
    }

    public function deleteLeaveType(LeaveType $leaveType): void
    {
        $leaveType->delete();
    }

    /**
     * Carry unused leave into the next leave year.
     *
     * Delegates to LeaveCarryOverService, which is the only correct
     * implementation. What used to be here did four destructive things: it
     * wrote the carried amount into allocated_days, replacing the new year's
     * fresh entitlement rather than adding to it; it set used_days to 0,
     * erasing every booking anyone had made; it ignored encashed_days, so days
     * already paid out were carried and counted twice; and it worked in
     * calendar years, which cannot express a 1 July to 30 June leave year at
     * all.
     *
     * Kept as a method rather than deleted because a console command still
     * calls it, and a caller reaching a removed method is a fatal error where
     * a caller reaching a corrected one simply behaves.
     *
     * @param  int  $targetYear  the leave year to carry INTO, as its start year
     * @return array{employees:int, rows:int, days:float}
     */
    public function carryForwardBalances(int $targetYear): array
    {
        $resolver = app(LeaveYearResolver::class);

        $to = $resolver->forDate(Carbon::create($targetYear, 7, 1));
        $from = $resolver->previous($to);

        return app(LeaveCarryOverService::class)->execute($from, $to);
    }

    public function creditCompOff(Employee $employee, Carbon $date, float $days = 1.0): LeaveBalance
    {
        $leaveType = LeaveType::firstOrCreate(
            ['category' => 'comp_off'],
            [
                'name' => 'Comp Off',
                'code' => 'CO',
                'is_paid' => true,
                'color' => '#06B6D4',
                'allow_carry_forward' => true,
                'carry_forward_limit' => 0,
                'allow_encashment' => false,
            ],
        );

        // The leave year of the day worked (not its calendar year), recorded
        // as an add-on lot so it can expire and be consumed traceably.
        return app(LeaveMovementService::class)->creditCompOff($employee, $leaveType, $date, $days);
    }

    /**
     * A balance row, defaulting to the CURRENT LEAVE YEAR.
     *
     * Not the calendar year. With a 1 July start the two disagree from January
     * to June, and defaulting to the calendar year silently read and wrote the
     * wrong row for half of every year.
     *
     * Callers that know which leave date they are acting on should use
     * balanceForDate() instead of relying on this default.
     */
    public function getBalance(int $employeeId, int $leaveTypeId, ?int $year = null): ?LeaveBalance
    {
        $year ??= app(LeaveYearResolver::class)->legacyYearFor();

        return LeaveBalance::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->first();
    }

    /**
     * The balance row that a given leave date belongs to.
     *
     * Historical and boundary-crossing leave is the reason this exists: a day
     * taken on 20 June 2025 must reach the 2024/25 balance however long ago it
     * was, and whatever today happens to be.
     */
    public function balanceForDate(int $employeeId, int $leaveTypeId, CarbonInterface $date): ?LeaveBalance
    {
        return $this->getBalance(
            $employeeId,
            $leaveTypeId,
            app(LeaveYearResolver::class)->legacyYearFor($date),
        );
    }

    /**
     * Request leave encashment.
     *
     * By default only carried-forward (previous-year) days are eligible.
     * When allow_current_year_encashment is enabled on the leave type, the
     * employee may also encash from their current-year allocated balance.
     * A per-year encashment cap (max_encashable_days) is enforced when set.
     */
    /**
     * What an employee can encash of a leave type right now, in the current
     * leave year — the same LeaveBalanceCalculator figure every leave screen
     * shows, less leave awaiting approval and encashments already in the
     * pipeline (one day can be taken or paid out, never both).
     *
     * @return array{available: float, carry_forward: float, current_year: float, in_pipeline: float, remaining_cap: ?float}
     */
    public function encashable(Employee $employee, LeaveType $leaveType): array
    {
        $none = ['available' => 0.0, 'carry_forward' => 0.0, 'current_year' => 0.0, 'in_pipeline' => 0.0, 'remaining_cap' => null];

        if (! $leaveType->allow_encashment || $leaveType->category === 'comp_off') {
            return $none;
        }

        $year = app(LeaveYearResolver::class)->current();
        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->first();

        if ($balance === null) {
            return $none;
        }

        $summary = app(LeaveBalanceCalculator::class)->summary($balance);
        $inPipeline = round((float) LeaveEncashment::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->whereIn('status', ['pending', 'pending_finance'])
            ->sum('requested_days'), 2);

        $free = max(0.0, round($summary['available_to_request'] - $inPipeline, 2));
        $carry = max(0.0, min($free, round($summary['carry_forward'] - $summary['encashed'] - $inPipeline, 2)));
        $current = $leaveType->allow_current_year_encashment ? max(0.0, round($free - $carry, 2)) : 0.0;

        $cap = null;
        if ($leaveType->max_encashable_days !== null) {
            $already = (float) LeaveEncashment::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->whereNotIn('status', ['rejected'])
                ->whereBetween('created_at', [$year->starts_on->copy()->startOfDay(), $year->ends_on->copy()->endOfDay()])
                ->sum('requested_days');
            $cap = max(0.0, (float) $leaveType->max_encashable_days - $already);
        }

        return [
            'available' => round($carry + $current, 2),
            'carry_forward' => $carry,
            'current_year' => $current,
            'in_pipeline' => $inPipeline,
            'remaining_cap' => $cap,
        ];
    }

    public function requestEncashment(Employee $employee, LeaveType $leaveType, float $requestedDays, string $payoutMonth): LeaveEncashment
    {
        // Conexus policy: only CSL is encashable. Comp Off never is, whatever
        // a type's flag says, and MDL is not a balance at all.
        if (! $leaveType->allow_encashment || $leaveType->category === 'comp_off') {
            throw new \DomainException("Leave type '{$leaveType->name}' is not eligible for encashment.");
        }

        if ($requestedDays <= 0) {
            throw new \DomainException('Enter the number of days to encash.');
        }

        $year = app(LeaveYearResolver::class)->current();
        $figures = $this->encashable($employee, $leaveType);
        $encashable = $figures['available'];
        $inPipeline = $figures['in_pipeline'];

        if ($requestedDays > $encashable + 0.001) {
            throw new \DomainException(
                "Insufficient {$leaveType->name} to encash. Available: {$encashable} day(s)"
                .($inPipeline > 0 ? " after {$inPipeline} day(s) already awaiting encashment approval" : '')
                .", requested: {$requestedDays}."
            );
        }

        // Per-leave-year cap (a leave year, not a calendar year).
        if ($leaveType->max_encashable_days !== null) {
            $alreadyEncashed = LeaveEncashment::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->whereNotIn('status', ['rejected'])
                ->whereBetween('created_at', [$year->starts_on->copy()->startOfDay(), $year->ends_on->copy()->endOfDay()])
                ->sum('requested_days');

            $remainingCap = max(0, $leaveType->max_encashable_days - $alreadyEncashed);

            if ($requestedDays > $remainingCap) {
                throw new \DomainException(
                    "Encashment cap of {$leaveType->max_encashable_days} days per leave year reached. You may encash at most {$remainingCap} more day(s) in {$year->label}."
                );
            }
        }

        return LeaveEncashment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'requested_days' => $requestedDays,
            // Where the days come from: carried-forward days originated in the
            // previous leave year; anything beyond them is this year's.
            'source_leave_year' => $requestedDays <= $figures['carry_forward'] + 0.001 ? $year->legacyYear() - 1 : $year->legacyYear(),
            'status' => 'pending',
            'payout_month' => $payoutMonth,
        ]);
    }

    /**
     * The final decision on an encashment (D3, 8 Oct 2026): a Director or the
     * HR Admin approves and that is final — pending → approved, CSL debited
     * now. There is no Finance approval stage; Finance processes and verifies
     * the amount when payroll pays it.
     */
    public function approveEncashment(User $reviewer, LeaveEncashment $encashment, string $comment = ''): void
    {
        if ($encashment->status !== 'pending') {
            throw new \DomainException('Only pending encashment requests can be approved at this stage.');
        }

        // Conexus policy: encashment is approved by a Director or the HR
        // Admin (Super Admin included) — not by any leave approver.
        if (! ($reviewer->isSuperAdmin() || $reviewer->isHrAdmin() || $reviewer->role === UserRole::Director)) {
            throw new \DomainException('Leave encashment must be approved by a Director or the HR Admin.');
        }

        app(ApprovalGuard::class)->assertNotSelf($reviewer, $encashment->employee);

        DB::transaction(function () use ($reviewer, $encashment, $comment) {
            $encashment->update([
                'status' => 'approved',
                'reviewer_id' => $reviewer->id,
                'reviewer_comment' => $comment,
                'reviewed_at' => now(),
                // Payroll pays approved encashments by payout month; one approved
                // after its month's run is carried into the next open run (D8).
                'payout_month' => max((string) $encashment->payout_month, now()->format('Y-m')),
            ]);

            $this->debitEncashedDays($encashment, $reviewer, 'Leave encashment approved');
        });

        $encashment->load(['employee.user', 'leaveType']);
        $encashment->employee->user->notify((new LeaveEncashmentNotification($encashment, 'approved'))->forRole('employee'));
    }

    /**
     * HR / manager or finance reject — valid from pending or pending_finance.
     */
    public function rejectEncashment(User $reviewer, LeaveEncashment $encashment, string $reason): void
    {
        if (! in_array($encashment->status, ['pending', 'pending_finance'])) {
            throw new \DomainException('Only pending or pending-finance encashments may be rejected.');
        }

        app(ApprovalGuard::class)->assertNotSelf($reviewer, $encashment->employee);

        $isFinanceStage = $encashment->status === 'pending_finance';

        // Each stage is rejected by whoever may approve it: the Director / HR
        // Admin stage first, Finance only once it has reached Finance.
        if ($isFinanceStage && ! $reviewer->canApproveFinance()) {
            throw new \DomainException('Only Finance can reject an encashment awaiting Finance.');
        }

        if (! $isFinanceStage && ! ($reviewer->isSuperAdmin() || $reviewer->isHrAdmin() || $reviewer->role === UserRole::Director)) {
            throw new \DomainException('Leave encashment must be decided by a Director or the HR Admin at this stage.');
        }

        $encashment->update(array_merge(
            [
                'status' => 'rejected',
                'reviewer_comment' => $reason,
                'reviewed_at' => now(),
            ],
            $isFinanceStage ? [
                'finance_reviewer_id' => $reviewer->id,
                'finance_reviewer_comment' => $reason,
                'finance_reviewed_at' => now(),
            ] : [
                'reviewer_id' => $reviewer->id,
            ]
        ));

        $encashment->load(['employee.user', 'leaveType']);
        $encashment->employee->user->notify((new LeaveEncashmentNotification($encashment, 'rejected'))->forRole('employee'));
    }

    /**
     * Finish an encashment still parked in pending_finance from before D3:
     * pending_finance → approved, CSL debited. New approvals never go there.
     */
    public function financeApproveEncashment(User $reviewer, LeaveEncashment $encashment, string $comment = ''): void
    {
        if ($encashment->status !== 'pending_finance') {
            throw new \DomainException('Only pending-finance encashments can be finance-approved.');
        }

        app(ApprovalGuard::class)->assertNotSelf($reviewer, $encashment->employee);

        DB::transaction(function () use ($reviewer, $encashment, $comment) {
            $encashment->update([
                'status' => 'approved',
                'finance_reviewer_id' => $reviewer->id,
                'finance_reviewer_comment' => $comment,
                'finance_reviewed_at' => now(),
                // Payroll picks approved encashments up by payout month. One
                // signed off after its month's run would never be paid, so it
                // moves to the current month.
                'payout_month' => max((string) $encashment->payout_month, now()->format('Y-m')),
            ]);

            $this->debitEncashedDays($encashment, $reviewer, 'Leave encashment approved by finance');
        });

        $encashment->load(['employee.user', 'leaveType']);
        $encashment->employee->user->notify((new LeaveEncashmentNotification($encashment, 'approved'))->forRole('employee'));
    }

    /**
     * Debit approved encashment days from the balance they were requested
     * from: the leave year the request was made in (carried-forward days live
     * in that year's row even though source_leave_year names the year they
     * originated in), so an approval after 1 July still debits the right row.
     * Idempotent through the ledger key.
     */
    private function debitEncashedDays(LeaveEncashment $encashment, User $actor, string $reason): void
    {
        $year = app(LeaveYearResolver::class)->legacyYearFor($encashment->created_at ?? now());
        $balance = LeaveBalance::where('employee_id', $encashment->employee_id)
            ->where('leave_type_id', $encashment->leave_type_id)
            ->where('year', $year)
            ->first();

        if (! $balance) {
            return;
        }

        if (app(LeaveMovementService::class)->ledgerReady($balance)) {
            $ledger = app(LeaveLedgerService::class);
            $ledger->debit($balance, LeaveLedgerEntry::TYPE_ENCASHMENT, (float) $encashment->requested_days,
                Carbon::today(), "encashment:{$encashment->id}", [
                    'source_type' => 'leave_encashment', 'source_id' => $encashment->id,
                    'reason' => $reason, 'actor' => $actor,
                ]);
            $ledger->rebuild($balance);
        } else {
            $balance->increment('encashed_days', $encashment->requested_days);
        }
    }

    /**
     * Flag absences with no approved leave as Unauthorized Leave — for HR to
     * decide. D10 (8 Oct 2026): the flag is a PENDING request; nothing is
     * treated as unpaid until a person approves it (approve = loss of pay,
     * reject = not an absence). Only an approved row reaches LWP.
     */
    public function autoFlagUnauthorizedAbsences(Carbon $date): int
    {
        $unauthorizedType = LeaveType::where('category', 'unauthorized')->first();

        if (! $unauthorizedType) {
            return 0;
        }

        // Not a working day for anyone: the weekly off (Saturday + Sunday), or
        // a Mandatory December Leave shutdown day (spec §3.3 — no leave consumed).
        $days = app(WorkingDayResolver::class);
        if (! $days->isCompanyWorkingDay($date)) {
            return 0;
        }

        $flagged = 0;
        $employees = Employee::where('status', 'active')->with(['attendances', 'leaveRequests', 'exitRecord'])->get();

        foreach ($employees as $employee) {
            // Only a scheduled working day can be an absence: never before
            // joining, after leaving, or on a holiday on their calendar
            // (spec §3.3: no leave is consumed). Leave is checked below.
            if ($days->classify($employee, $date, withLeave: false) !== WorkingDayResolver::WORKING_DAY) {
                continue;
            }

            $hasAttendance = $employee->attendances()
                ->where('date', $date->toDateString())
                ->whereNotNull('check_in')
                ->exists();

            if ($hasAttendance) {
                continue;
            }

            $hasApprovedLeave = LeaveRequest::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $date->toDateString())
                ->where('end_date', '>=', $date->toDateString())
                ->exists();

            if ($hasApprovedLeave) {
                continue;
            }

            $hasPendingLeave = LeaveRequest::where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'pending_hr'])
                ->where('start_date', '<=', $date->toDateString())
                ->where('end_date', '>=', $date->toDateString())
                ->exists();

            if ($hasPendingLeave) {
                continue;
            }

            $alreadyFlagged = LeaveRequest::where('employee_id', $employee->id)
                ->where('leave_type_id', $unauthorizedType->id)
                ->where('start_date', $date->toDateString())
                ->exists();

            if ($alreadyFlagged) {
                continue;
            }

            LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $unauthorizedType->id,
                'start_date' => $date->toDateString(),
                'end_date' => $date->toDateString(),
                'is_half_day' => false,
                'days' => 1,
                'reason' => 'Auto-flagged: absent without approved leave or regularisation.',
                'requested_leave_status' => 'unpaid',
                'status' => 'pending',
            ]);

            $flagged++;
        }

        return $flagged;
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Cancel a leave request — employee-initiated, allowed while pending or pending_hr.
     * If already approved and paid, the used_days balance is restored.
     */
    public function cancelRequest(LeaveRequest $leaveRequest, ?string $reason = null, ?User $actor = null, array $meta = []): void
    {
        if (! in_array($leaveRequest->status, ['pending', 'pending_hr', 'approved'])) {
            throw new \DomainException('Only pending or approved leave requests can be cancelled.');
        }

        // An absence flag is HR's to resolve (D10); the employee answers it
        // with a regularisation or a leave request, never by withdrawing it.
        $isOwnAction = $actor === null || (int) $actor->id === (int) $leaveRequest->employee?->user_id;
        if ($isOwnAction && $leaveRequest->leaveType?->category === 'unauthorized') {
            throw new \DomainException('An absence flag is resolved by HR — submit a regularisation or a leave request for that day instead.');
        }

        $employee = $leaveRequest->employee;

        if ($leaveRequest->status === 'approved' && $this->wasApprovedAsPaid($leaveRequest)) {
            // Returned to the leave year the leave was taken in — cancelling
            // June leave in July must not credit the new year.
            app(LeaveMovementService::class)->reverseUsage(
                $leaveRequest,
                (int) $leaveRequest->leave_type_id,
                (float) $leaveRequest->days,
                Carbon::parse($leaveRequest->start_date),
                $reason ? 'Leave cancelled: '.$reason : 'Leave cancelled',
                $actor ?? auth()->user(),
            );
        }

        $oldStatus = $leaveRequest->status;
        $leaveRequest->update(['status' => 'cancelled']);
        $this->auditDecision($leaveRequest, $oldStatus, 'cancelled', [], $reason, $actor ?? auth()->user(), $meta);
    }

    /**
     * One categorised audit event per leave decision (approve, reject,
     * forward to HR, cancel, or a change to an already-decided request), with
     * the status and figures before and after and the reviewer's comment as
     * the reason. The generic LeaveRequest observer row stays as the raw
     * field-level record.
     *
     * @param  array<string, mixed>  $before  figures captured before the update, when they could change
     * @param  array<string, mixed>  $meta  extra context, e.g. a supporting document
     */
    private function auditDecision(LeaveRequest $leaveRequest, ?string $oldStatus, string $newStatus, array $before, ?string $comment, ?User $actor, array $meta = []): void
    {
        $decided = in_array($oldStatus, ['approved', 'rejected', 'cancelled'], true);

        $event = match (true) {
            $decided && $newStatus !== 'cancelled' => 'LEAVE_DECISION_CHANGED',
            $newStatus === 'approved' => 'LEAVE_APPROVED',
            $newStatus === 'rejected' => 'LEAVE_REJECTED',
            $newStatus === 'pending_hr' => 'LEAVE_FORWARDED_TO_HR',
            $newStatus === 'cancelled' => 'LEAVE_CANCELLED',
            default => 'LEAVE_STATUS_CHANGED',
        };

        $after = $leaveRequest->only(['leave_type_id', 'start_date', 'end_date', 'days']);
        $after['start_date'] = Carbon::parse($after['start_date'])->toDateString();
        $after['end_date'] = Carbon::parse($after['end_date'])->toDateString();

        app(AuditService::class)->event(
            $event,
            AuditService::LEAVE,
            $leaveRequest,
            old: ['status' => $oldStatus] + $before,
            new: ['status' => $newStatus] + $after + $meta,
            reason: $comment,
            subjectEmployeeId: $leaveRequest->employee_id,
            actor: $actor,
        );
    }

    /**
     * Reviewer must be in scope and not the requester. A still-open request
     * (pending / pending_hr / more_info_requested) may be decided by any
     * in-scope approver; re-opening an already-decided one (approved,
     * rejected, cancelled) is an HR correction and needs employee-management
     * authority.
     *
     * @throws ApprovalNotPermitted
     */
    private function assertCanReview(LeaveRequest $leaveRequest, int $reviewerId): void
    {
        $reviewer = User::find($reviewerId);

        app(ApprovalGuard::class)->assertCanDecide($reviewer, $leaveRequest->employee);

        $isOpen = in_array($leaveRequest->status, ['pending', 'pending_hr', 'more_info_requested'], true);

        if (! $isOpen && ! ($reviewer->isSuperAdmin() || $reviewer->canManageEmployees())) {
            throw new \DomainException('This leave request has already been decided and can only be corrected by HR.');
        }
    }

    private function wasApprovedAsPaid(LeaveRequest $leaveRequest): bool
    {
        $effective = $leaveRequest->approved_leave_status
            ?? $leaveRequest->requested_leave_status;

        if ($effective !== null) {
            return $effective === 'paid';
        }

        // Legacy requests without status fields — fall back to leave type default
        return (bool) $leaveRequest->leaveType?->is_paid;
    }

    private function checkOverlapAndDeductBalance(LeaveRequest $leaveRequest, Employee $employee, array $data, float $newDays): void
    {
        $overlap = LeaveRequest::where('employee_id', $employee->id)
            ->where('id', '!=', $leaveRequest->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $data['end_date'])
            ->where('end_date', '>=', $data['start_date'])
            ->first();

        if ($overlap) {
            throw new \DomainException(
                "Cannot approve: employee already has approved leave from {$overlap->start_date->format('d M Y')} to {$overlap->end_date->format('d M Y')}."
            );
        }

        $effectiveStatus = $leaveRequest->approved_leave_status
            ?? $leaveRequest->requested_leave_status
            ?? ($leaveRequest->leaveType?->is_paid ? 'paid' : 'unpaid');

        if ($effectiveStatus === 'paid') {
            // Posted to the leave year the leave itself falls in — not today's.
            // A ledger-backed balance records which credit lots it consumed.
            app(LeaveMovementService::class)->recordUsage(
                $leaveRequest,
                $newDays,
                (int) $data['leave_type_id'],
                Carbon::parse($data['start_date']),
            );
        }
    }

    private function writePaymentAuditLog(
        LeaveRequest $leaveRequest,
        int $changedById,
        string $fromStatus,
        string $toStatus,
        string $reason,
        string $stage,
    ): void {
        LeavePaymentAuditLog::create([
            'leave_request_id' => $leaveRequest->id,
            'changed_by' => $changedById,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'stage' => $stage,
        ]);
    }
}
