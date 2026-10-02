<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveYear;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveRuleResolver;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Find (and with --apply, provision) eligible employees missing their base
 * entitlement for a leave year. Applying only posts missing entitlement;
 * mismatches and ambiguous balances are reported for HR, never reset.
 */
#[Signature('leave:ensure-balances {--year= : Leave year label, e.g. 2026/27 (defaults to the current year)} {--employee= : Limit to one employee id} {--apply : Provision what is missing (otherwise preview only)}')]
#[Description('Detect and provision missing leave entitlements for a leave year')]
class EnsureLeaveBalances extends Command
{
    public function handle(EnsureEmployeeLeaveBalancesService $ensure, LeaveYearResolver $years): int
    {
        $year = $this->option('year')
            ? LeaveYear::where('label', $this->option('year'))->first()
            : $years->current();

        if ($year === null) {
            $this->error('No leave year labelled '.$this->option('year').'.');

            return self::FAILURE;
        }

        $employees = Employee::whereIn('status', LeaveRuleResolver::ELIGIBLE_STATUSES)
            ->when($this->option('employee'), fn ($q, $id) => $q->whereKey($id))
            ->with('user')
            ->get();

        $result = $ensure->bulk($employees, $year, null, dryRun: ! $this->option('apply'), trigger: 'scheduled_detection');

        $this->table(['Employee', 'Leave type', 'Expected', 'Current base', 'Status', 'Note'],
            $result['rows']->whereNotIn('status', [
                EnsureEmployeeLeaveBalancesService::ALREADY, EnsureEmployeeLeaveBalancesService::INELIGIBLE,
                EnsureEmployeeLeaveBalancesService::NO_ENTITLEMENT, EnsureEmployeeLeaveBalancesService::ACCRUAL_ONLY,
            ])->map(fn (array $r) => [$r['employee'], $r['leave_type'], $r['expected'], $r['current_base'], $r['status'], $r['message']])->all());

        $s = $result['summary'];
        $this->info(sprintf('%s %s: %d employee(s), %d to provision, %d already done, %d warning(s), %d without a policy.',
            $this->option('apply') ? 'Applied' : 'Preview', $year->label, $s['selected'], $s['valid'], $s['already_processed'], $s['warnings'], $s['no_policy']));

        return self::SUCCESS;
    }
}
