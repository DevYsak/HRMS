<?php

namespace App\Services\Leave;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\LeaveAccrualLog;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly and quarterly leave accrual (Phase 2B).
 *
 * Accrual only ever ADDS its own credit — an ACCRUAL ledger lot in the leave
 * year of the month being credited. Base, carry forward, add-on, adjustments
 * and usage are never touched. (The original implementation reset
 * allocated, used and carried forward to zero before adding the month.)
 *
 * Idempotent twice over: one LeaveAccrualLog per employee, type, year and
 * month (unique index), and the ledger credit is keyed on that log.
 *
 * Settings come from the employee's LeavePolicyRule, falling back to the
 * leave type (is_monthly_accrual / accrual_days_per_month):
 *  - monthly credits every month; quarterly in the first month of each
 *    quarter of the leave year;
 *  - nothing before the accrual start (joining date, or confirmation);
 *  - the joining month follows joining_month_rule;
 *  - max_accumulation caps the approved available balance.
 */
class LeaveAccrualService
{
    public function __construct(
        private readonly LeaveRuleResolver $rules,
        private readonly LeaveMovementService $movements,
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveYearResolver $years,
    ) {}

    /**
     * Credit one calendar month for every eligible employee and type.
     *
     * @return array{credited: int, skipped: int, capped: int, already: int}
     */
    public function run(int $year, int $month): array
    {
        $result = ['credited' => 0, 'skipped' => 0, 'capped' => 0, 'already' => 0];
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $leaveYear = $this->years->forDate($monthStart);
        $types = LeaveType::whereNull('deleted_at')->get();

        Employee::whereIn('status', LeaveRuleResolver::ELIGIBLE_STATUSES)->with('leavePolicy')
            ->chunkById(200, function ($employees) use ($types, $monthStart, $leaveYear, $year, $month, &$result) {
                foreach ($employees as $employee) {
                    foreach ($types as $type) {
                        $outcome = $this->creditOne($employee, $type, $monthStart, $leaveYear, $year, $month);
                        $result[$outcome]++;
                    }
                }
            });

        return $result;
    }

    /** @return 'credited'|'skipped'|'capped'|'already' */
    public function creditOne(Employee $employee, LeaveType $type, Carbon $monthStart, $leaveYear, int $year, int $month): string
    {
        $settings = $this->rules->settings($employee, $type);

        if (! in_array($settings['accrual_method'], [LeavePolicyRule::ACCRUAL_MONTHLY, LeavePolicyRule::ACCRUAL_QUARTERLY], true)
            || $settings['accrual_amount'] <= 0
            || ! $this->rules->isEligible($employee, $type, $leaveYear)) {
            return 'skipped';
        }

        $status = $employee->status instanceof EmployeeStatus ? $employee->status->value : (string) $employee->status;
        if ($settings['probation_restricted'] && $status === 'probation') {
            return 'skipped';
        }

        if ($settings['accrual_method'] === LeavePolicyRule::ACCRUAL_QUARTERLY
            && $leaveYear->starts_on->diffInMonths($monthStart) % 3 !== 0) {
            return 'skipped';
        }

        if (! $this->startedBy($employee, $settings, $monthStart)) {
            return 'skipped';
        }

        if (LeaveAccrualLog::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->where('year', $year)->where('month', $month)->exists()) {
            return 'already';
        }

        return DB::transaction(function () use ($employee, $type, $monthStart, $year, $month, $settings) {
            $balance = $this->movements->balanceFor($employee->id, $type->id, $monthStart);
            // Onto the ledger before this month's log exists, so the
            // migration does not count the accrual it is about to receive.
            $this->movements->ledgerReady($balance);
            $balance->refresh();
            $days = (float) $settings['accrual_amount'];

            // The cap limits what accrual may add, never removes what is there.
            if ($settings['max_accumulation'] !== null) {
                $room = round($settings['max_accumulation'] - $this->calculator->summary($balance)['approved_available'], 2);
                if ($room <= 0) {
                    return 'capped';
                }
                $days = min($days, $room);
            }

            try {
                $log = LeaveAccrualLog::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $type->id,
                    'days_credited' => $days,
                    'year' => $year,
                    'month' => $month,
                    'credited_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent run got there first.
                return 'already';
            }

            $this->movements->creditAccrual($balance, $log->id, $days, $monthStart);

            return 'credited';
        });
    }

    /** Whether accrual has started by this month (joining date / confirmation, joining-month rule). */
    private function startedBy(Employee $employee, array $settings, Carbon $monthStart): bool
    {
        $start = $this->rules->accrualStart($employee, $settings['accrual_start']);

        if ($start === null) {
            return false;
        }

        $start = Carbon::parse($start)->startOfDay();

        if ($start->copy()->startOfMonth()->gt($monthStart)) {
            return false;
        }

        if ($start->copy()->startOfMonth()->lt($monthStart)) {
            return true;
        }

        // The starting month itself.
        return match ($settings['joining_month_rule']) {
            LeavePolicyRule::JOINING_FULL => true,
            LeavePolicyRule::JOINING_NONE => $start->day === 1,
            default => $start->day <= 15,
        };
    }
}
