<?php

namespace App\Services\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Closing a leave year (Phase 2A lifecycle).
 *
 * open → closed. A year may only close once it is genuinely finished:
 *  - its last day has passed;
 *  - no request whose leave falls in it is still awaiting a decision;
 *  - every balance in it is on the ledger and none awaits HR review.
 * (From Phase 2C the year-end rollover must also have completed.)
 *
 * Once closed, LeaveLedgerService refuses ordinary postings to the year;
 * only an explicitly authorised historical correction may touch it.
 */
class LeaveYearLifecycleService
{
    /**
     * Why the year cannot close yet — empty when it can.
     *
     * @return array<int, string>
     */
    public function blockersToClose(LeaveYear $year): array
    {
        $blockers = [];

        if ($year->isClosed()) {
            return ["{$year->label} is already closed."];
        }

        if ($year->ends_on->gte(Carbon::today())) {
            $blockers[] = "{$year->label} has not ended yet (ends {$year->ends_on->toDateString()}).";
        }

        $pending = LeaveRequest::whereIn('status', LeaveBalanceCalculator::RESERVING_STATUSES)
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())
            ->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->count();
        if ($pending > 0) {
            $blockers[] = "{$pending} leave request(s) in {$year->label} are still awaiting a decision.";
        }

        $inYear = LeaveBalance::where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()));

        $unmigrated = (clone $inYear)->whereNull('ledger_migrated_at')->count();
        if ($unmigrated > 0) {
            $blockers[] = "{$unmigrated} balance(s) in {$year->label} are not on the leave ledger yet.";
        }

        $review = (clone $inYear)->where('ledger_status', LeaveBalance::LEDGER_NEEDS_HR_REVIEW)->count();
        if ($review > 0) {
            $blockers[] = "{$review} balance(s) in {$year->label} still need HR review.";
        }

        return $blockers;
    }

    public function close(LeaveYear $year, User $actor, string $reason): LeaveYear
    {
        if ($blockers = $this->blockersToClose($year)) {
            throw new DomainException(implode(' ', $blockers));
        }

        $year->forceFill(['is_closed' => true, 'closed_at' => now(), 'closed_by' => $actor->id])->save();

        app(AuditService::class)->event('LEAVE_YEAR_CLOSED', AuditService::LEAVE, $year,
            old: ['is_closed' => false], new: ['is_closed' => true, 'label' => $year->label], reason: $reason);

        return $year;
    }

    /** Re-opening a closed year is a Super Admin decision, always audited. */
    public function reopen(LeaveYear $year, User $actor, string $reason): LeaveYear
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('Only a Super Admin can re-open a closed leave year.');
        }

        if (trim($reason) === '') {
            throw new DomainException('Re-opening a leave year needs a reason.');
        }

        $year->forceFill(['is_closed' => false, 'closed_at' => null, 'closed_by' => null])->save();

        app(AuditService::class)->event('LEAVE_YEAR_REOPENED', AuditService::LEAVE, $year,
            old: ['is_closed' => true], new: ['is_closed' => false, 'label' => $year->label], reason: $reason);

        return $year;
    }
}
