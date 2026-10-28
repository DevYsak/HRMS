<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveRolloverRecord;
use App\Models\LeaveType;
use App\Models\LeaveV2Migration;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leave V2 Earned Leave year-end (Jul–Jun): after the final June accrual,
 * carry the unused Earned Leave into the new leave year as the policy says,
 * and open the new year's balance — which then accrues month by month.
 *
 * The carry is one ledger credit in the NEW year under a deterministic key
 * (earned-carry-forward:{employee}:{old_year}:{new_year}); the closing year's
 * balance and ledger are never touched. A rollover record marks the employee
 * done, so a second run — or a zero carry — never posts twice.
 *
 * Carry forward is policy-driven (rule: enabled, maximum days, expiry). An
 * enabled carry forward with no maximum is BLOCKED as needing configuration
 * — never treated as unlimited. Pending requests, an unfinished year, a
 * missing final accrual or an overdrawn balance are reported, never guessed.
 */
class EarnedLeaveRolloverService
{
    public const READY = 'READY';

    public const DONE = 'DONE';

    public const REVIEW = 'NEEDS REVIEW';

    public const BLOCKED = 'BLOCKED';

    /** Left before the new year: nothing is carried. */
    public const NOT_ELIGIBLE = 'NOT ELIGIBLE';

    public const KEY_PREFIX = 'earned-carry-forward';

    public const SOURCE_TYPE = 'earned_leave_rollover';

    private const PENDING = ['pending', 'pending_hr', 'more_info_requested', 'awaiting_employee_confirmation'];

    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeavePolicyAssignmentResolver $assignments,
        private readonly LeaveBalanceSource $sources,
        private readonly LeaveRuleResolver $rules,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveMovementService $movements,
        private readonly LeaveYearResolver $years,
        private readonly EarnedLeaveAccrualService $accrual,
    ) {}

    /**
     * @param  array<int, string>  $employeeCodes
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(LeaveYear $from, ?LeaveYear $to = null, array $employeeCodes = []): Collection
    {
        $to ??= $this->years->next($from);

        return Employee::with(['user', 'exitRecord'])
            ->when($employeeCodes !== [], fn ($q) => $q->whereIn('employee_id', $employeeCodes))
            ->orderBy('employee_id')->get()
            ->map(fn (Employee $employee) => $this->planFor($employee, $from, $to))
            ->filter(fn (array $row) => $row['earned_type_id'] !== null)
            ->values();
    }

    /** @return array<string, mixed> */
    public function planFor(Employee $employee, LeaveYear $from, LeaveYear $to): array
    {
        $policy = $this->assignments->policyFor($employee, Carbon::parse($from->ends_on));
        $earned = $this->sources->poolOf($policy);

        $row = [
            'employee_id' => $employee->id,
            'employee' => $employee->user?->name ?? 'Employee #'.$employee->id,
            'code' => $employee->employee_id,
            'policy' => $policy?->name,
            'earned_type_id' => $earned?->id,
            'from_year_id' => $from->id, 'from_year' => $from->label,
            'to_year_id' => $to->id, 'to_year' => $to->label,
            'closing' => null, 'carry' => 0.0, 'lapse' => 0.0, 'cap' => null, 'expires_on' => null,
            'status' => self::BLOCKED, 'reason' => '',
        ];
        $stop = function (string $status, string $reason) use (&$row): array {
            return ['status' => $status, 'reason' => $reason] + $row;
        };

        if (! $earned) {
            return $row;
        }

        $record = LeaveRolloverRecord::where('employee_id', $employee->id)->where('leave_type_id', $earned->id)
            ->where('from_leave_year_id', $from->id)->where('status', LeaveRolloverRecord::PROCESSED)->first();
        if ($record) {
            return ['status' => self::DONE, 'closing' => (float) $record->closing_days, 'carry' => (float) $record->carry_days,
                'reason' => 'Rolled over '.$record->processed_at?->format('d M Y H:i').'.'] + $row;
        }

        // One carry forward per year: an opening already reclassified as carry
        // forward into the new year has brought the previous year in.
        if (LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $earned->id)->where('leave_year_id', $to->id)
            ->where('entry_type', LeaveLedgerEntry::TYPE_CARRY_FORWARD)->whereNull('reverses_entry_id')->whereDoesntHave('reversedBy')->exists()) {
            return $stop(self::REVIEW, "{$to->label} already holds an Earned Leave carry forward — a second one would count {$from->label} twice.");
        }

        if (Carbon::today()->lte($from->ends_on)) {
            return $stop(self::BLOCKED, "{$from->label} has not ended yet (ends ".Carbon::parse($from->ends_on)->format('d M Y').').');
        }

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $earned->id)->where('leave_year_id', $from->id)->first();
        if ($balance && ! $balance->isLedgerBacked()) {
            return $stop(self::REVIEW, 'The closing Earned Leave balance is not on the ledger.');
        }
        $closing = $balance ? (float) $this->calculator->summary($balance)['approved_available'] : 0.0;
        $row['closing'] = $closing;

        // Every completed month of the closing year must be credited first.
        $final = $this->accrual->planFor($employee, $from, Carbon::parse($to->starts_on));
        if ($final['status'] === EarnedLeaveAccrualService::READY) {
            return $stop(self::REVIEW, 'Final accrual not posted for '.implode(', ', $final['due_months']).' — run leave:accrue-earned first.');
        }
        if ($final['status'] === EarnedLeaveAccrualService::REVIEW) {
            return $stop(self::REVIEW, 'Accrual needs review first: '.$final['reason']);
        }
        if ($final['status'] === EarnedLeaveAccrualService::BLOCKED) {
            return $stop(self::BLOCKED, 'Earned Leave accrual is blocked: '.$final['reason']);
        }

        $lastDay = $employee->exitRecord?->last_working_day;
        if ($lastDay && Carbon::parse($lastDay)->lt($to->starts_on)) {
            return $stop(self::NOT_ELIGIBLE, 'Left on '.Carbon::parse($lastDay)->format('d M Y').': nothing carries into '.$to->label.'.');
        }

        $pending = LeaveRequest::where('employee_id', $employee->id)->whereIn('status', self::PENDING)
            ->chargedTo($earned->id)->whereDate('start_date', '<=', Carbon::parse($from->ends_on)->toDateString())->count();
        if ($pending > 0) {
            return $stop(self::REVIEW, "{$pending} pending request(s) in {$from->label} still charge Earned Leave — decide them first.");
        }
        if ($closing < -0.005) {
            return $stop(self::REVIEW, "The closing Earned Leave balance is overdrawn ({$closing}).");
        }

        $rule = LeavePolicyRule::where('leave_policy_id', $policy->id)->where('leave_type_id', $earned->id)->where('is_active', true)->first();
        if (! $rule?->carry_forward_enabled) {
            return ['status' => self::READY, 'carry' => 0.0, 'lapse' => round($closing, 2),
                'reason' => 'Carry forward is off on the policy: nothing carries; the new year starts at 0 and accrues monthly.'] + $row;
        }
        if ($rule->carry_forward_max_days === null) {
            return $stop(self::BLOCKED, 'Carry forward is on but no maximum is configured on the policy — set the company limit before the rollover.');
        }

        $cap = (float) $rule->carry_forward_max_days;
        $carry = round(max(0.0, min($closing, $cap)), 2);
        $settings = $this->rules->settings($employee, $earned, Carbon::parse($from->ends_on));

        return ['status' => self::READY, 'carry' => $carry, 'lapse' => round(max(0.0, $closing - $carry), 2), 'cap' => $cap,
            'expires_on' => $this->rules->carryForwardExpiry($settings, $to),
            'reason' => "Carry {$carry} of {$closing} (policy maximum {$cap})."] + $row;
    }

    /**
     * Carry one READY employee forward in a single transaction: the carry
     * credit in the new year (deterministic key), the new year's balance and
     * the rollover record — re-judged first.
     *
     * @param  array<string, mixed>  $planned
     * @return array<string, mixed>
     */
    public function apply(array $planned, ?User $actor = null, string $trigger = 'manual'): array
    {
        return DB::transaction(function () use ($planned, $actor, $trigger) {
            $employee = Employee::with(['user', 'exitRecord'])->findOrFail($planned['employee_id']);
            $from = LeaveYear::findOrFail($planned['from_year_id']);
            $to = LeaveYear::findOrFail($planned['to_year_id']);
            $row = $this->planFor($employee, $from, $to);
            if ($row['status'] !== self::READY) {
                return $row;
            }

            $earned = LeaveType::findOrFail($row['earned_type_id']);
            $balance = $this->movements->balanceFor($employee->id, $earned->id, Carbon::parse($to->starts_on));
            $this->movements->ledgerReady($balance);

            $entry = null;
            if ($row['carry'] > 0.005) {
                $entry = $this->ledger->credit($balance->fresh(), LeaveLedgerEntry::TYPE_CARRY_FORWARD, $row['carry'], Carbon::parse($to->starts_on),
                    $this->keyFor($employee, $from, $to), [
                        'source_type' => self::SOURCE_TYPE,
                        'reason' => "Earned Leave carried forward from {$from->label}",
                        'actor' => $actor,
                        'expires_on' => $row['expires_on'],
                        'meta' => ['from_year' => $from->label, 'closing' => $row['closing'], 'lapsed' => $row['lapse'], 'cap' => $row['cap'],
                            'trigger' => $trigger, 'actor' => $actor ? 'user:'.$actor->id : 'system:'.$trigger],
                    ]);
                $this->ledger->rebuild($balance->fresh());
            }

            LeaveRolloverRecord::create([
                'employee_id' => $employee->id, 'leave_type_id' => $earned->id,
                'from_leave_year_id' => $from->id, 'to_leave_year_id' => $to->id,
                'status' => LeaveRolloverRecord::PROCESSED, 'closing_days' => $row['closing'],
                'carry_days' => $row['carry'], 'expired_days' => $row['lapse'], 'new_base_days' => 0,
                'message' => $row['reason'], 'processed_by' => $actor?->id, 'processed_at' => now(),
            ]);

            app(AuditService::class)->event('LEAVE_EARNED_CARRIED_FORWARD', AuditService::LEAVE, $balance->fresh(),
                old: ['leave_year' => $from->label, 'closing' => $row['closing']],
                new: ['leave_year' => $to->label, 'carried' => $row['carry'], 'lapsed' => $row['lapse'], 'expires_on' => $row['expires_on'],
                    'ledger_entry_id' => $entry?->id, 'trigger' => $trigger, 'actor' => $actor ? $actor->email : 'system'],
                reason: $actor ? 'Year-end rollover run by '.$actor->name : 'System: Earned Leave year-end rollover ('.$trigger.')',
                subjectEmployeeId: $employee->id, actor: $actor);

            return ['status' => 'ROLLED OVER', 'reason' => $entry ? "Carried {$row['carry']} into {$to->label} (ledger #{$entry->id})." : "Nothing carried; {$to->label} opened."] + $row;
        });
    }

    public function keyFor(Employee $employee, LeaveYear $from, LeaveYear $to): string
    {
        return self::KEY_PREFIX.":{$employee->id}:{$from->id}:{$to->id}";
    }

    /** The generic rollover leaves this balance alone: Leave V2 owns it, or it is historical. */
    public static function handledByLeaveV2(LeaveBalance $balance, LeaveYear $from): bool
    {
        $employee = $balance->employee;
        if (! $employee) {
            return false;
        }

        $pool = app(LeaveBalanceSource::class)->earnedPool($employee, Carbon::parse($from->ends_on));
        if ($pool && $pool->id === $balance->leave_type_id) {
            return true;
        }

        // A migrated employee's CSL is history: it is never carried a second time beside Earned Leave.
        return $balance->leaveType?->code === ConexusLeavePolicyService::CSL_CODE
            && LeaveV2Migration::where('employee_id', $employee->id)->exists();
    }
}
