<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Services\Leave\ConexusCslAccrualService;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Put the Conexus Standard Leave Policy in place and restate every employee's
 * 2026/27 CSL to the HR register, through the leave ledger.
 *
 * Without --apply the whole run is simulated inside one database transaction
 * and rolled back: the report shows exactly what --apply would post and
 * whether every row would PASS, and nothing is saved.
 *
 * With --apply the policy is applied, then each employee is reconciled in
 * their own transaction and verified before it commits; an employee that does
 * not verify is rolled back on their own and reported FAIL. Re-running is
 * safe: a reconciled employee posts nothing the second time.
 *
 * The register's current-year credit stands for the months earned when it
 * was taken (2 = July and August). With --with-accrual the run then credits
 * the months completed since (leave:conexus-csl-accrual) for the register
 * staff — so the preview proves those 2 days are not credited a second time
 * and shows the balances that will actually result.
 *
 * Exit code 0 only when every register row PASSes (and, with the accrual,
 * every employee and policy check PASSes).
 */
#[Signature('leave:conexus-reconcile
    {--apply : Save the changes (otherwise a rolled-back preview)}
    {--register= : Register file (defaults to database/data/conexus_csl_register_2026_27.php)}
    {--actor= : Email of the HR/admin user recorded on every ledger entry}
    {--employee=* : Limit to these register emails}
    {--skip-unlisted : Do not touch employees outside the register (their Annual Leave stays as is)}
    {--with-accrual : Also credit the months completed since the register snapshot (leave:conexus-csl-accrual)}
    {--csl-type= : Id of the existing Paid Leave type to use as CSL, when it cannot be found unambiguously by name}
    {--as-of= : Date the accrual runs as of (default today)}
    {--details : Print every ledger movement per employee}')]
#[Description('Apply the Conexus leave policy and reconcile CSL to the 2026/27 HR register')]
class ReconcileConexusLeave extends Command
{
    public function handle(ConexusLeavePolicyService $policy, LeaveRegisterReconciliationService $reconciler, ConexusCslAccrualService $accrual): int
    {
        $apply = (bool) $this->option('apply');
        $path = $this->option('register') ?: database_path('data/conexus_csl_register_2026_27.php');

        try {
            $register = $reconciler->loadRegister($path);
            $year = $reconciler->yearFor($register['leave_year']);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $actor = $this->option('actor') ? User::where('email', $this->option('actor'))->first() : null;
        if ($this->option('actor') && $actor === null) {
            $this->error('No user with email '.$this->option('actor').'.');

            return self::FAILURE;
        }

        $this->info(($apply ? 'APPLYING' : 'PREVIEW (nothing will be saved)').' — '.$register['source']);
        $this->line(sprintf('Register verified: %d rows, credit %s, carry %s, used %s, available %s. Leave year %s (%s – %s).',
            $register['checksums']['rows'], $register['checksums']['credit'], $register['checksums']['carry'],
            $register['checksums']['used'], $register['checksums']['available'], $year->label,
            $year->starts_on->toDateString(), $year->ends_on->toDateString()));

        $cslTypeId = $this->option('csl-type') !== null ? (int) $this->option('csl-type') : null;
        $plan = $policy->plan($year, $cslTypeId);
        $this->newLine();
        $this->info('Policy');
        foreach ($plan['actions'] as $action) {
            $this->line('  • '.$action);
        }
        foreach ($plan['warnings'] as $warning) {
            $this->warn('  ! '.$warning);
        }
        if ($plan['blocked'] !== []) {
            foreach ($plan['blocked'] as $blocked) {
                $this->error('  ✗ '.$blocked);
            }

            return self::FAILURE;
        }

        // Evidence that the type being renamed is the one holding the
        // reconciled data: how many register staff already have a balance on it.
        $found = collect($register['employees'])->map(fn (array $row) => $reconciler->findEmployee($row))->filter();
        $holding = LeaveBalance::where('leave_type_id', $plan['csl']['type_id'])->whereIn('employee_id', $found->pluck('id'))->distinct()->count('employee_id');
        $this->line(sprintf('  • CSL type #%d: %d of the %d register staff found here already hold a balance on it.',
            $plan['csl']['type_id'], $holding, $found->count()));

        if (! $apply) {
            DB::beginTransaction();
        }

        try {
            $applied = $policy->apply($actor, $cslTypeId);
            $csl = $applied['csl'];
            $annual = $applied['legacy_annual'];
            $compOff = $applied['comp_off'];

            $rows = collect($register['employees'])
                ->when($this->option('employee'), fn ($c, $only) => $c->filter(
                    fn ($r) => collect($r['emails'])->map(fn ($e) => strtolower($e))->intersect(array_map('strtolower', $only))->isNotEmpty()
                ));

            $report = [];
            $matchedIds = [];

            foreach ($rows as $row) {
                $employee = $reconciler->findEmployee($row);

                if ($employee === null) {
                    $report[] = $this->failRow($row, 'No employee with a login matching '.implode(' / ', $row['emails']).'.');

                    continue;
                }

                $matchedIds[] = $employee->id;

                try {
                    $log = $reconciler->reconcileEmployee($employee, $row, $year, $csl, $annual, $actor);
                    $figures = $reconciler->report($employee, $row, $year, $csl, $annual, $compOff);
                    $status = $reconciler->matches($figures, $row) ? LeaveRegisterReconciliationService::PASS : LeaveRegisterReconciliationService::FAIL;
                    $report[] = $figures + ['status' => $status, 'note' => $log === [] ? 'Already reconciled.' : count($log).' movement(s)', 'log' => $log];
                } catch (Throwable $e) {
                    $figures = $reconciler->report($employee, $row, $year, $csl, $annual, $compOff);
                    $report[] = $figures + ['status' => LeaveRegisterReconciliationService::FAIL, 'note' => $e->getMessage(), 'log' => []];
                }
            }

            $unlisted = [];
            if (! $this->option('skip-unlisted') && ! $this->option('employee') && $annual) {
                foreach (Employee::whereNotIn('id', $matchedIds)->with('user')->get() as $employee) {
                    try {
                        $log = $reconciler->reconcileEmployee($employee, null, $year, $csl, $annual, $actor);
                        if ($log !== []) {
                            $unlisted[] = [$employee->user?->name ?? '#'.$employee->id, $employee->id, 'Annual Leave taken to zero ('.count($log).' movement(s)); CSL not restated — not in the register.'];
                        }
                    } catch (Throwable $e) {
                        $unlisted[] = [$employee->user?->name ?? '#'.$employee->id, $employee->id, 'FAIL: '.$e->getMessage()];
                    }
                }
            }

            $this->printReport($report, $register);

            if ($unlisted !== []) {
                $this->newLine();
                $this->info('Employees outside the register');
                $this->table(['Name', 'DB ID', 'Result'], $unlisted);
            }

            $accrualPassed = true;
            if ($this->option('with-accrual') && collect($report)->contains('status', LeaveRegisterReconciliationService::PASS)) {
                $asOf = $this->option('as-of') ? Carbon::parse($this->option('as-of'))->startOfDay() : Carbon::today();
                $this->newLine();
                $this->info("Months earned since the register snapshot (as of {$asOf->toDateString()})");
                // Only staff whose reconciliation PASSED: a failed one was rolled
                // back, so its register credit is not in place to count from.
                $passedIds = collect($report)->where('status', LeaveRegisterReconciliationService::PASS)->pluck('employee_id')->all();
                $emails = Employee::with('user')->whereKey($passedIds)->get()->map(fn (Employee $e) => $e->user?->email)->filter()->values()->all();
                $accrualPassed = ConexusCslAccrual::render($this, $accrual->run($csl, $asOf, true, $actor, $emails));
            }
        } catch (Throwable $e) {
            if (! $apply) {
                DB::rollBack();
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $apply) {
            DB::rollBack();
            $this->newLine();
            $this->warn('Preview only — every change above was simulated in a transaction and rolled back. Re-run with --apply to save.');
        }

        $failed = collect($report)->where('status', LeaveRegisterReconciliationService::FAIL)->count();

        return $failed === 0 && count($report) === $rows->count() && $accrualPassed ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function failRow(array $row, string $note): array
    {
        return [
            'name' => $row['name'], 'employee_id' => '—', 'employee_code' => '—',
            'csl_credit' => '—', 'csl_accrued' => '—', 'csl_carry' => '—', 'csl_used' => '—', 'csl_encashed' => '—', 'csl_available' => '—',
            'comp_off' => '—', 'mdl_days' => '—', 'legacy_annual_active' => '—', 'ledger_backed' => false,
            'status' => LeaveRegisterReconciliationService::FAIL, 'note' => $note, 'log' => [],
        ];
    }

    private function printReport(array $report, array $register): void
    {
        $this->newLine();
        $this->info('Reconciliation report — '.$register['leave_year']);

        $this->table(
            ['Name', 'DB ID', 'Code', 'CSL credit', 'Accrued since', 'Carry', 'Used', 'Encashed', 'CSL avail', 'Comp Off', 'MDL days', 'Legacy AL', 'Ledger', 'Result'],
            collect($report)->map(fn ($r) => [
                $r['name'], $r['employee_id'], $r['employee_code'] ?? '—',
                $r['csl_credit'], $r['csl_accrued'], $r['csl_carry'], $r['csl_used'], $r['csl_encashed'], $r['csl_available'],
                $r['comp_off'], $r['mdl_days'], $r['legacy_annual_active'], $r['ledger_backed'] ? 'YES' : 'NO', $r['status'],
            ])->all(),
        );

        foreach ($report as $r) {
            if ($r['status'] === LeaveRegisterReconciliationService::FAIL) {
                $this->error("  ✗ {$r['name']}: {$r['note']}");
            }
            if ($this->option('details')) {
                foreach ($r['log'] as $line) {
                    $this->line("    {$r['name']}: {$line}");
                }
            }
        }

        $numeric = collect($report)->filter(fn ($r) => is_numeric($r['csl_credit']));
        $passed = collect($report)->where('status', LeaveRegisterReconciliationService::PASS)->count();

        $this->newLine();
        $accrued = round($numeric->sum('csl_accrued'), 2);
        $this->line(sprintf('Totals after reconciliation: credit %s (expected %s), carry %s (expected %s), used %s (expected %s), available %s (expected %s%s).',
            round($numeric->sum('csl_credit'), 2), $register['checksums']['credit'],
            round($numeric->sum('csl_carry'), 2), $register['checksums']['carry'],
            round($numeric->sum('csl_used'), 2), $register['checksums']['used'],
            round($numeric->sum('csl_available'), 2), $register['checksums']['available'],
            $accrued > 0 ? " + {$accrued} accrued since the snapshot" : ''));
        $this->line(sprintf('%d PASS, %d FAIL.', $passed, count($report) - $passed));
    }
}
