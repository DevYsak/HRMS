<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DecemberMandatoryDay;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\PayrollService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finds the unpaid "Auto-flagged" absences the nightly job wrongly created on
 * days that were never working days — a public holiday, a Mandatory December
 * Leave shutdown day, the weekly off, or a date before the employee joined —
 * and (with --apply) cancels the ones no payroll has paid yet.
 *
 * Preview by default: nothing changes without --apply. A day already inside
 * a finalized payroll is listed but never touched — it was paid as loss of
 * pay, so correcting it is an arrears decision for HR / Finance, not a
 * silent rewrite of a closed month. Every cancellation is audit logged.
 */
#[Signature('hrms:review-auto-absences {--from= : Earliest date to check (default: 1 July of the current leave year)} {--to= : Latest date (default: yesterday)} {--apply : Cancel the eligible rows}')]
#[Description('Preview (or with --apply, cancel) auto-flagged unpaid absences created on holidays, MDL days, weekly offs or before joining')]
class ReviewAutoFlaggedAbsences extends Command
{
    /** The reason the nightly job writes — only its own rows are ever touched. */
    private const AUTO_REASON = 'Auto-flagged: absent without approved leave or regularisation.';

    public function handle(HolidayResolver $holidays, PayrollService $payroll): int
    {
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : now()->subDay();
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : Carbon::create(now()->month >= 7 ? now()->year : now()->year - 1, 7, 1);

        $candidates = LeaveRequest::with(['employee.user', 'leaveType'])
            ->where('reason', self::AUTO_REASON)
            ->where('status', 'approved')
            ->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('start_date')
            ->get();

        $rows = [];
        $toCancel = [];

        foreach ($candidates as $request) {
            $date = Carbon::parse($request->start_date);
            $employee = $request->employee;
            $why = match (true) {
                $employee?->joining_date && $employee->joining_date->gt($date) => 'before joining',
                DecemberMandatoryDay::isMandatory($date) => 'MDL shutdown day',
                $employee && $holidays->isHoliday($employee, $date) => 'public holiday',
                app(WorkingDayResolver::class)->isWeeklyOff($date) => 'weekly off',
                default => null,
            };

            if ($why === null) {
                continue; // a genuine working day — the flag stands
            }

            [$action, $cancellable] = $this->payrollState($payroll, $request->employee_id, $date);
            $rows[] = [
                $employee?->user?->name ?? '#'.$request->employee_id,
                $date->toDateString(),
                $why,
                $action,
                '#'.$request->id,
            ];

            if ($cancellable) {
                $toCancel[] = $request;
            }
        }

        $this->info("Auto-flagged absences on non-working days, {$from->toDateString()} – {$to->toDateString()}:");
        $this->table(['Employee', 'Date', 'Not a working day because', 'Action', 'Request'], $rows);
        $this->line(count($rows).' found; '.count($toCancel).' can be cancelled; '.(count($rows) - count($toCancel)).' already in a payslip (listed only — HR / Finance decide).');

        if (! $this->option('apply')) {
            $this->warn('Preview only — nothing was changed. Re-run with --apply to cancel the eligible rows.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toCancel) {
            foreach ($toCancel as $request) {
                $request->update(['status' => 'cancelled']);
                AuditLog::record(
                    $request, 'cancelled',
                    ['status' => 'approved'], ['status' => 'cancelled'],
                    reason: 'Auto-flagged unpaid absence fell on a non-working day (hrms:review-auto-absences).',
                    subjectEmployeeId: $request->employee_id,
                );
            }
        });

        $this->info('Cancelled '.count($toCancel).' auto-flagged absence(s).');

        return self::SUCCESS;
    }

    /**
     * What a payslip covering the date means for cancelling the absence.
     *
     * The loss-of-pay day is computed into the payslip when the run is
     * generated. A finalized run has paid it (an arrears decision); a run
     * awaiting Finance, or a locked payslip, cannot be regenerated, so
     * cancelling would leave the deduction with no record behind it — listed
     * for HR / Finance, not cancelled. Only an unlocked draft (regenerate it
     * afterwards) or no covering payslip at all is safe to cancel.
     *
     * @return array{0: string, 1: bool} [action shown, may cancel]
     */
    private function payrollState(PayrollService $payroll, int $employeeId, Carbon $date): array
    {
        $covering = Payslip::with('payroll')->where('employee_id', $employeeId)->get()
            ->filter(function (Payslip $slip) use ($payroll, $date) {
                if (! $slip->payroll) {
                    return false;
                }

                try {
                    [$start, $end] = $payroll->resolveCycleDates($slip->payroll->month, (int) $slip->payroll->year, $slip->payroll->cycle);
                } catch (\DomainException) {
                    return true; // an unknown cycle: assume it covers, and do not cancel
                }

                return $date->betweenIncluded($start, $end);
            });

        if ($covering->isEmpty()) {
            return ['cancel', true];
        }

        if ($finalized = $covering->first(fn (Payslip $slip) => $slip->payroll->status === 'finalized')) {
            return ["PAID ({$finalized->payroll->month} {$finalized->payroll->year} payroll) — arrears decision", false];
        }

        if ($held = $covering->first(fn (Payslip $slip) => $slip->payroll->status !== 'draft' || $slip->isLocked())) {
            $state = $held->isLocked() ? 'locked payslip' : str_replace('_', ' ', $held->payroll->status);

            return ["in {$held->payroll->month} {$held->payroll->year} payroll ({$state}) — decide with Finance", false];
        }

        $draft = $covering->first();

        return ["cancel — then regenerate the {$draft->payroll->month} {$draft->payroll->year} draft", true];
    }
}
