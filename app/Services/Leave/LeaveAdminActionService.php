<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Audit\AuditService;
use App\Services\LeaveService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * HR's corrections to leave that has already happened or been decided —
 * always through the ledger and the existing services, never by writing a
 * balance:
 *
 *   recordApproved      leave recorded on an employee's behalf as approved
 *                       (submitted through the normal rules, then approved)
 *   correctApproved     new dates / type for approved leave (the old usage
 *                       is reversed and the new usage posted)
 *   cancelApproved      approved (or pending) leave cancelled; paid usage
 *                       is returned to the balance it came from
 *   reverseEntry        one HR ledger movement taken back (an HR credit or
 *                       debit, or an add-on lot such as Comp Off)
 *
 * Each needs its own permission, a reason, never the actor's own record, and
 * writes a categorised audit event with before and after. History is never
 * deleted: reversals are new entries.
 */
class LeaveAdminActionService
{
    /** HR movements that may be reversed one by one from the ledger. */
    public const REVERSIBLE_TYPES = [
        LeaveLedgerEntry::TYPE_ADD_ON,
        LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT,
        LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT,
    ];

    public function __construct(
        private LeaveService $leave,
        private LeaveLedgerService $ledger,
        private LeaveBalanceCalculator $calculator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     *
     * @throws AuthorizationException|\DomainException
     */
    public function recordApproved(
        User $hr,
        Employee $employee,
        LeaveType $type,
        string $startDate,
        string $endDate,
        string $reason,
        bool $isHalfDay = false,
        ?string $halfDayPeriod = null,
        string $paymentStatus = 'paid',
        ?string $internalNote = null,
        bool $notifyEmployee = true,
        array $attachments = [],
    ): LeaveRequest {
        $this->authorise($hr, 'record_approved_leave', $employee);

        return DB::transaction(function () use ($hr, $employee, $type, $startDate, $endDate, $reason, $isHalfDay, $halfDayPeriod, $paymentStatus, $internalNote, $notifyEmployee, $attachments) {
            // The normal submission: every leave rule and the balance check apply.
            $request = $this->leave->applyOnBehalf(
                $hr, $employee, $type, $startDate, $endDate, $reason, $isHalfDay, $halfDayPeriod,
                $paymentStatus, $internalNote, $notifyEmployee, $attachments,
            );

            $this->leave->reviewRequest($request, [
                'leave_type_id' => $request->leave_type_id,
                'start_date' => $request->start_date->toDateString(),
                'end_date' => $request->end_date->toDateString(),
                'is_half_day' => (bool) $request->is_half_day,
                'reason' => $request->reason,
            ], 'approved', $hr->id, 'Recorded as approved by HR: '.$reason);

            $request->refresh();

            if ($request->status !== 'approved') {
                throw new \DomainException('Only HR can record leave as already approved.');
            }

            return $request;
        });
    }

    /**
     * @param  array{leave_type_id: int, start_date: string, end_date: string, is_half_day?: bool}  $changes
     *
     * @throws AuthorizationException|\DomainException
     */
    public function correctApproved(LeaveRequest $request, User $hr, array $changes, string $reason): LeaveRequest
    {
        $this->authorise($hr, 'manage_approved_leave', $request->employee);

        if ($request->status !== 'approved') {
            throw new \DomainException('Only approved leave can be corrected here.');
        }

        // reviewRequest reverses the old usage and posts the new one in the
        // same transaction, and records LEAVE_DECISION_CHANGED with the reason.
        return $this->leave->reviewRequest($request, [
            'leave_type_id' => $changes['leave_type_id'],
            'start_date' => $changes['start_date'],
            'end_date' => $changes['end_date'],
            'is_half_day' => (bool) ($changes['is_half_day'] ?? false),
            'reason' => $request->reason,
        ], 'approved', $hr->id, $reason);
    }

    /** @throws AuthorizationException|\DomainException */
    public function cancelApproved(LeaveRequest $request, User $hr, string $reason, ?string $documentPath = null): void
    {
        $this->authorise($hr, 'manage_approved_leave', $request->employee);

        DB::transaction(fn () => $this->leave->cancelRequest(
            $request, $reason, $hr, array_filter(['cancelled_by' => 'hr', 'document' => $documentPath]),
        ));
    }

    /** @throws AuthorizationException|\DomainException */
    public function reverseEntry(LeaveLedgerEntry $entry, User $hr, string $reason): LeaveLedgerEntry
    {
        $employee = Employee::findOrFail($entry->employee_id);
        $this->authorise($hr, 'correct_leave_balance', $employee);

        if (! in_array($entry->entry_type, self::REVERSIBLE_TYPES, true)) {
            throw new \DomainException('Only HR credits, debits and add-on lots can be reversed here. Cancel the leave request, or reverse the carry forward, instead.');
        }

        $balance = $this->balanceOf($entry);
        $before = $this->calculator->summary($balance)['approved_available'];

        $reversal = DB::transaction(function () use ($entry, $hr, $reason, $balance) {
            $reversal = $this->ledger->reverse($entry, $reason, $hr);
            $this->ledger->rebuild($balance);

            return $reversal;
        });

        app(AuditService::class)->event('LEAVE_LEDGER_ENTRY_REVERSED', AuditService::LEAVE, $entry,
            old: ['entry_type' => $entry->entry_type, 'days' => (float) $entry->days, 'approved_available' => $before],
            new: ['reversal_entry_id' => $reversal->id, 'approved_available' => $this->calculator->summary($balance->fresh())['approved_available']],
            reason: $reason, subjectEmployeeId: $employee->id, actor: $hr);

        return $reversal;
    }

    /** Store a supporting document privately, per employee. */
    public function storeDocument(UploadedFile $file, Employee $employee): string
    {
        return $file->store("leave-documents/{$employee->id}", 'local');
    }

    /** @throws AuthorizationException|\DomainException */
    private function authorise(User $hr, string $permission, ?Employee $employee): void
    {
        if (! $hr->hasPermission($permission)) {
            throw new AuthorizationException('You do not have permission to do this.');
        }

        if ($employee === null) {
            throw new \DomainException('This leave has no employee.');
        }

        if ($hr->employee?->id === $employee->id) {
            throw new \DomainException('You cannot change your own leave. Another HR user must.');
        }

        app(ApprovalGuard::class)->assertCanDecide($hr, $employee);
    }

    private function balanceOf(LeaveLedgerEntry $entry): LeaveBalance
    {
        // As the ledger resolves it: linked by leave year, or by the legacy year integer.
        $year = LeaveYear::findOrFail($entry->leave_year_id);

        return LeaveBalance::where('employee_id', $entry->employee_id)
            ->where('leave_type_id', $entry->leave_type_id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->firstOrFail();
    }
}
