<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\LeaveAccrualLog;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeavePaymentAuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Notifications\LeaveEncashmentNotification;
use App\Notifications\LeaveMonthlyAccrualNotification;
use App\Notifications\LeavePaymentStatusChangedNotification;
use App\Notifications\LeaveRequestNotification;
use App\Services\Teams\ApprovalRoutingService;
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

        // Without sandwich: count only weekdays
        $total = 0.0;
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
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
        if (! $leaveType->is_sandwich_applicable || $start->isWeekend() || $end->isWeekend()) {
            return [$start->copy(), $end->copy()];
        }

        $bridgedStart = $start->copy();
        $bridgedEnd = $end->copy();

        // Backward: walk over the run of weekend days immediately before the
        // start; if a leave block sits on the working day just beyond it, the
        // weekend is trapped, so pull the start back to the first weekend day.
        $probe = $start->copy()->subDay();
        $weekendRun = 0;
        while ($probe->isWeekend()) {
            $weekendRun++;
            $probe->subDay();
        }
        if ($weekendRun > 0 && $this->hasLeaveOn($employee->id, $probe, $excludeRequestId)) {
            $bridgedStart = $probe->copy()->addDay();
        }

        // Forward: mirror image after the end date.
        $probe = $end->copy()->addDay();
        $weekendRun = 0;
        while ($probe->isWeekend()) {
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
     * The first active company holiday within [start, end] that applies to
     * the given employee (respecting branch/department/employee scope), or
     * null. Used to block leave that overlaps a holiday.
     */
    public function holidayWithinRange(Employee $employee, Carbon $start, Carbon $end): ?PublicHoliday
    {
        return PublicHoliday::query()
            ->active()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->forEmployee($employee)
            ->orderBy('date')
            ->get()
            ->first(fn (PublicHoliday $h) => $h->appliesToEmployee($employee));
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

        // Half-day period required when half day selected
        if ($isHalfDay && $halfDayPeriod === null) {
            throw new \DomainException('Please specify first half or second half for the half-day leave.');
        }

        // Half day allowed by leave type
        if ($isHalfDay && ! $leaveType->allow_half_day) {
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
        if ($leaveType->probation_restricted && $employee->status === EmployeeStatus::Probation) {
            throw new \DomainException("'{$leaveType->name}' is not available during probation.");
        }

        // Notice period restriction
        if ($leaveType->notice_period_restricted && $employee->status === EmployeeStatus::NoticePeriod) {
            throw new \DomainException("'{$leaveType->name}' is not available during notice period.");
        }

        // Weekend block — Saturdays and Sundays are non-working days and can't
        // be picked as leave. Weekends that merely fall inside a longer range
        // (e.g. a Fri→Mon leave) are fine — they're simply not counted.
        if ($start->isWeekend()) {
            throw new \DomainException($start->format('l, d M Y').' is a weekend (non-working day) — please pick a working day as your start date.');
        }
        if ($end->isWeekend()) {
            throw new \DomainException($end->format('l, d M Y').' is a weekend (non-working day) — please pick a working day as your end date.');
        }

        // Company holiday block — leave cannot include a holiday that applies
        // to this employee (branch/department/employee scope respected).
        $holiday = $this->holidayWithinRange($employee, $start, $end);
        if ($holiday) {
            throw new \DomainException(
                Carbon::parse($holiday->date)->format('d M Y')." ({$holiday->name}) is already a company holiday. Please exclude it from your leave dates."
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

        if ($leaveType->max_consecutive_days !== null && $days > $leaveType->max_consecutive_days) {
            throw new \DomainException(
                "'{$leaveType->name}' allows a maximum of {$leaveType->max_consecutive_days} consecutive day(s)."
            );
        }

        // Attachment required
        if ($leaveType->attachment_required && $attachmentPath === null) {
            throw new \DomainException("An attachment is required for '{$leaveType->name}'.");
        }

        // Overlap check — must run before balance check so conflict errors surface first
        $overlap = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'pending', 'pending_hr'])
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
            $balance = $this->getBalance($employee->id, $leaveType->id);
            $available = $balance
                ? max(0, (float) $balance->allocated_days - (float) $balance->used_days - (float) ($balance->encashed_days ?? 0))
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

        $notifiedIds = $recipients->pluck('id')->all();
        User::whereIn('role', ['hr_admin', 'super_admin'])
            ->when(\count($notifiedIds), fn ($q) => $q->whereNotIn('id', $notifiedIds))
            ->get()->each($push);

        $recipients->each(fn (User $u) => $u->notify(new LeaveRequestNotification($request)));

        return $request;
    }

    /**
     * First-stage review (manager / dept head).
     * Managers route to pending_hr; HR Admins and Super Admins approve directly.
     */
    public function reviewRequest(
        LeaveRequest $leaveRequest,
        array $data,
        string $status,
        int $reviewerId,
        ?string $comment = null,
    ): LeaveRequest {
        return DB::transaction(function () use ($leaveRequest, $data, $status, $reviewerId, $comment) {
            $oldStatus = $leaveRequest->status;
            $oldDays = (float) $leaveRequest->days;
            $oldTypeId = $leaveRequest->leave_type_id;
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
                $this->getBalance($employee->id, $oldTypeId)?->decrement('used_days', $oldDays);
            }

            $reviewer = User::find($reviewerId);
            $isHrReviewer = $reviewer && \in_array($reviewer->role?->value ?? $reviewer->role, ['hr_admin', 'super_admin'], true);
            $resolvedStatus = ($status === 'approved' && ! $isHrReviewer) ? 'pending_hr' : $status;

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

            $fresh = $leaveRequest->fresh(['employee.user', 'leaveType', 'reviewer']);
            $fresh->employee->user->notify(new LeaveRequestNotification($fresh));

            if ($resolvedStatus === 'pending_hr') {
                User::whereIn('role', ['hr_admin', 'super_admin'])
                    ->each(fn ($hr) => $hr->notify(new LeaveRequestNotification($fresh)));
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
            $manager?->notify(new LeaveRequestNotification($fresh));
            User::whereIn('role', ['hr_admin', 'super_admin'])
                ->when($manager, fn ($q) => $q->where('id', '!=', $manager->id))
                ->each(fn ($hr) => $hr->notify(new LeaveRequestNotification($fresh)));
        } else {
            $fresh->employee->user?->notify(new LeaveRequestNotification($fresh));
        }

        return $fresh;
    }

    /**
     * HR second-stage approval — called after manager sets status to pending_hr.
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

            $leaveRequest->update($updateData);

            if ($decision === 'approved' && $effectiveStatus === 'paid') {
                $balance = $this->getBalance($employee->id, $leaveRequest->leave_type_id);
                if ($balance) {
                    $balance->increment('used_days', $newDays);
                }
            }

            $fresh = $leaveRequest->fresh(['employee.user', 'leaveType', 'reviewer', 'hrReviewer']);
            $fresh->employee->user->notify(new LeaveRequestNotification($fresh));

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
                if ($currentStatus === 'paid' && $newStatus === 'unpaid') {
                    // Reverse the deduction
                    $this->getBalance($employee->id, $leaveRequest->leave_type_id)?->decrement('used_days', $days);
                } elseif ($currentStatus === 'unpaid' && $newStatus === 'paid') {
                    // Check balance and apply
                    $balance = $this->getBalance($employee->id, $leaveRequest->leave_type_id);
                    $available = $balance
                        ? max(0, (float) $balance->allocated_days - (float) $balance->used_days - (float) ($balance->encashed_days ?? 0))
                        : 0;

                    if ($available < $days) {
                        throw new \DomainException(
                            "Insufficient balance to convert to paid leave. Available: {$available} day(s), required: {$days}."
                        );
                    }

                    $balance->increment('used_days', $days);
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
        $accrualTypes = LeaveType::where('is_monthly_accrual', true)
            ->where('accrual_days_per_month', '>', 0)
            ->get();

        if ($accrualTypes->isEmpty()) {
            return 0;
        }

        $count = 0;
        $employees = Employee::whereIn('status', [
            'active', 'probation', 'confirmed', 'on_leave',
        ])->get();

        DB::transaction(function () use ($employees, $accrualTypes, $year, $month, &$count) {
            foreach ($employees as $employee) {
                foreach ($accrualTypes as $type) {
                    // Skip if probation restricted and employee is on probation
                    if ($type->probation_restricted && $employee->status->value === 'probation') {
                        continue;
                    }

                    // Skip if gender restricted
                    if (! $type->isGenderEligible($employee->gender)) {
                        continue;
                    }

                    // Skip if already accrued this month
                    $alreadyAccrued = LeaveAccrualLog::where('employee_id', $employee->id)
                        ->where('leave_type_id', $type->id)
                        ->where('year', $year)
                        ->where('month', $month)
                        ->exists();

                    if ($alreadyAccrued) {
                        continue;
                    }

                    $days = (float) $type->accrual_days_per_month;

                    // Credit to leave balance
                    LeaveBalance::updateOrCreate([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => $year,
                    ], [
                        'allocated_days' => 0,
                        'used_days' => 0,
                        'carried_forward_days' => 0,
                        'encashed_days' => 0,
                        'comp_off_credits' => 0,
                    ]);

                    LeaveBalance::where('employee_id', $employee->id)
                        ->where('leave_type_id', $type->id)
                        ->where('year', $year)
                        ->increment('allocated_days', $days);

                    // Write accrual log
                    LeaveAccrualLog::create([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'days_credited' => $days,
                        'year' => $year,
                        'month' => $month,
                        'credited_at' => now(),
                    ]);

                    $count++;
                }
            }
        });

        // Notify HR admins with summary
        if ($count > 0) {
            User::whereIn('role', ['hr_admin', 'super_admin'])
                ->each(fn ($hr) => $hr->notify(new LeaveMonthlyAccrualNotification($count, $year, $month)));
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

    public function carryForwardBalances(int $targetYear): void
    {
        $sourceYear = $targetYear - 1;
        $activeEmployees = Employee::where('status', 'active')->get();
        $leaveTypes = LeaveType::all();

        DB::transaction(function () use ($activeEmployees, $leaveTypes, $targetYear, $sourceYear) {
            foreach ($activeEmployees as $employee) {
                foreach ($leaveTypes as $type) {
                    if (! $type->allow_carry_forward) {
                        continue;
                    }

                    $balance = LeaveBalance::where('employee_id', $employee->id)
                        ->where('leave_type_id', $type->id)
                        ->where('year', $sourceYear)
                        ->first();

                    if (! $balance) {
                        continue;
                    }

                    $remaining = max(0, (float) $balance->allocated_days - (float) $balance->used_days);

                    $limit = $type->carry_forward_limit > 0 ? $type->carry_forward_limit : $remaining;
                    $carryForward = min($remaining, $limit);

                    LeaveBalance::updateOrCreate([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => $targetYear,
                    ], [
                        'allocated_days' => $carryForward,
                        'used_days' => 0,
                        'carried_forward_days' => $carryForward,
                        'encashed_days' => 0,
                        'comp_off_credits' => 0,
                    ]);
                }
            }
        });
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

        $balance = LeaveBalance::firstOrCreate([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => $date->year,
        ], [
            'allocated_days' => 0,
            'used_days' => 0,
            'carried_forward_days' => 0,
            'encashed_days' => 0,
            'comp_off_credits' => 0,
        ]);

        $balance->incrementEach([
            'allocated_days' => $days,
            'comp_off_credits' => $days,
        ]);

        return $balance->fresh();
    }

    public function getBalance(int $employeeId, int $leaveTypeId, ?int $year = null): ?LeaveBalance
    {
        $year ??= now()->year;

        return LeaveBalance::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->first();
    }

    /**
     * Request leave encashment.
     *
     * By default only carried-forward (previous-year) days are eligible.
     * When allow_current_year_encashment is enabled on the leave type, the
     * employee may also encash from their current-year allocated balance.
     * A per-year encashment cap (max_encashable_days) is enforced when set.
     */
    public function requestEncashment(Employee $employee, LeaveType $leaveType, float $requestedDays, string $payoutMonth): LeaveEncashment
    {
        if (! $leaveType->allow_encashment) {
            throw new \DomainException("Leave type '{$leaveType->name}' is not eligible for encashment.");
        }

        $currentYear = now()->year;
        $balance = $this->getBalance($employee->id, $leaveType->id, $currentYear);

        // Available carry-forward days (already-encashed CF days are deducted)
        $cfAvailable = $balance
            ? max(0, (float) $balance->carried_forward_days - (float) ($balance->encashed_days ?? 0))
            : 0.0;

        // Available current-year days (only when explicitly enabled)
        $cyAvailable = 0.0;
        if ($leaveType->allow_current_year_encashment && $balance) {
            $cyAvailable = max(0, (float) $balance->allocated_days - (float) $balance->used_days - (float) ($balance->encashed_days ?? 0));
        }

        $totalAvailable = $cfAvailable + $cyAvailable;

        if ($requestedDays > $totalAvailable) {
            throw new \DomainException(
                "Insufficient encashable balance. Available: {$totalAvailable} day(s) (carry-forward: {$cfAvailable}, current-year: {$cyAvailable}), requested: {$requestedDays}."
            );
        }

        // Enforce per-year encashment cap
        if ($leaveType->max_encashable_days !== null) {
            $alreadyEncashedThisYear = LeaveEncashment::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->whereNotIn('status', ['rejected'])
                ->whereYear('created_at', $currentYear)
                ->sum('requested_days');

            $remainingCap = max(0, $leaveType->max_encashable_days - $alreadyEncashedThisYear);

            if ($requestedDays > $remainingCap) {
                throw new \DomainException(
                    "Encashment cap of {$leaveType->max_encashable_days} days per year reached. You may encash at most {$remainingCap} more day(s) this year."
                );
            }
        }

        // Source year: carry-forward days come from previous year; current-year from current year
        $sourceYear = $requestedDays <= $cfAvailable ? $currentYear - 1 : $currentYear;

        return LeaveEncashment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'requested_days' => $requestedDays,
            'source_leave_year' => $sourceYear,
            'status' => 'pending',
            'payout_month' => $payoutMonth,
        ]);
    }

    /**
     * HR / manager first-stage approval: pending → pending_finance.
     * Balance is NOT touched until finance gives final approval.
     */
    public function approveEncashment(User $reviewer, LeaveEncashment $encashment, string $comment = ''): void
    {
        if ($encashment->status !== 'pending') {
            throw new \DomainException('Only pending encashment requests can be approved at this stage.');
        }

        $encashment->update([
            'status' => 'pending_finance',
            'reviewer_id' => $reviewer->id,
            'reviewer_comment' => $comment,
            'reviewed_at' => now(),
        ]);

        // Notify finance team
        $encashment->load(['employee.user', 'leaveType']);
        User::whereIn('role', ['finance', 'super_admin'])
            ->each(fn ($u) => $u->notify(new LeaveEncashmentNotification($encashment, 'pending_finance')));

        // Notify employee
        $encashment->employee->user->notify(new LeaveEncashmentNotification($encashment, 'pending_finance_employee'));
    }

    /**
     * HR / manager or finance reject — valid from pending or pending_finance.
     */
    public function rejectEncashment(User $reviewer, LeaveEncashment $encashment, string $reason): void
    {
        if (! in_array($encashment->status, ['pending', 'pending_finance'])) {
            throw new \DomainException('Only pending or pending-finance encashments may be rejected.');
        }

        $isFinanceStage = $encashment->status === 'pending_finance';

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
        $encashment->employee->user->notify(new LeaveEncashmentNotification($encashment, 'rejected'));
    }

    /**
     * Finance final approval: pending_finance → approved.
     * Commits the encashed_days to the balance here (not on submission).
     */
    public function financeApproveEncashment(User $reviewer, LeaveEncashment $encashment, string $comment = ''): void
    {
        if ($encashment->status !== 'pending_finance') {
            throw new \DomainException('Only pending-finance encashments can be finance-approved.');
        }

        DB::transaction(function () use ($reviewer, $encashment, $comment) {
            $encashment->update([
                'status' => 'approved',
                'finance_reviewer_id' => $reviewer->id,
                'finance_reviewer_comment' => $comment,
                'finance_reviewed_at' => now(),
            ]);

            // Commit balance deduction only on final approval
            $balance = LeaveBalance::where('employee_id', $encashment->employee_id)
                ->where('leave_type_id', $encashment->leave_type_id)
                ->where('year', now()->year)
                ->first();

            if ($balance) {
                $balance->increment('encashed_days', $encashment->requested_days);
            }
        });

        $encashment->load(['employee.user', 'leaveType']);
        $encashment->employee->user->notify(new LeaveEncashmentNotification($encashment, 'approved'));
    }

    /**
     * Auto-flag absences with no approved leave as Unauthorized Leave.
     */
    public function autoFlagUnauthorizedAbsences(Carbon $date): int
    {
        $unauthorizedType = LeaveType::where('category', 'unauthorized')->first();

        if (! $unauthorizedType) {
            return 0;
        }

        $flagged = 0;
        $employees = Employee::where('status', 'active')->with(['attendances', 'leaveRequests'])->get();

        foreach ($employees as $employee) {
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
                'approved_leave_status' => 'unpaid',
                'status' => 'approved',
                'approved_at' => now(),
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
    public function cancelRequest(LeaveRequest $leaveRequest): void
    {
        if (! in_array($leaveRequest->status, ['pending', 'pending_hr', 'approved'])) {
            throw new \DomainException('Only pending or approved leave requests can be cancelled.');
        }

        $employee = $leaveRequest->employee;

        if ($leaveRequest->status === 'approved' && $this->wasApprovedAsPaid($leaveRequest)) {
            $this->getBalance($employee->id, $leaveRequest->leave_type_id)
                ?->decrement('used_days', $leaveRequest->days);
        }

        $leaveRequest->update(['status' => 'cancelled']);
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
            $balance = $this->getBalance($employee->id, (int) $data['leave_type_id'])
                ?? LeaveBalance::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => (int) $data['leave_type_id'],
                    'year' => now()->year,
                    'allocated_days' => 0,
                    'used_days' => 0,
                    'carried_forward_days' => 0,
                    'encashed_days' => 0,
                    'comp_off_credits' => 0,
                ]);

            $available = max(0, (float) $balance->allocated_days - (float) $balance->used_days - (float) ($balance->encashed_days ?? 0));

            if ($available < $newDays) {
                throw new \DomainException("Insufficient leave balance. Available: {$available} day(s), requested: {$newDays}.");
            }

            $balance->increment('used_days', $newDays);
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
