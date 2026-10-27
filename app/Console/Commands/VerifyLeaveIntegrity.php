<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveYear;
use App\Services\Leave\LeaveIntegrityService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Read-only leave integrity report: duplicate accruals, deductions or carry
 * forwards, negative balances, orphan ledger rows, balance/ledger
 * mismatches, approved paid days vs ledger usage, and Combined Leave headers
 * vs their day rows. Nothing is repaired. Exit code 1 when anything is found.
 */
#[Signature('leave:verify
    {--year= : Leave year label, e.g. 2026/27 (default: the current leave year)}
    {--employee=* : Limit to these employee codes}
    {--csv= : Also write the findings to this CSV path}')]
#[Description('Read-only leave integrity report (duplicates, orphans, mismatches) — reports, never repairs')]
class VerifyLeaveIntegrity extends Command
{
    public function handle(LeaveIntegrityService $integrity, LeaveYearResolver $years): int
    {
        $year = $this->option('year') ? LeaveYear::where('label', $this->option('year'))->first() : $years->current();
        if (! $year) {
            $this->error('No leave year '.$this->option('year').'.');

            return self::FAILURE;
        }

        $ids = $this->option('employee') !== [] ? Employee::whereIn('employee_id', $this->option('employee'))->pluck('id')->all() : null;
        $findings = $integrity->scan($year, $ids);
        $codes = Employee::whereIn('id', $findings->pluck('employee_id')->filter()->unique())->pluck('employee_id', 'id');

        $this->line("<comment>Leave integrity · {$year->label} · read only — nothing is repaired</comment>");
        foreach (LeaveIntegrityService::CHECKS as $check) {
            $this->line(sprintf('  %-32s %d', $check, $findings->where('check', $check)->count()));
        }

        if ($findings->isNotEmpty()) {
            $this->table(['Check', 'Employee', 'Reference', 'Detail'],
                $findings->map(fn (array $f) => [$f['check'], $codes[$f['employee_id']] ?? '—', $f['reference'], $f['detail']])->all());
        } else {
            $this->info('  No findings.');
        }

        if ($path = $this->option('csv')) {
            $out = fopen($path, 'w');
            fputcsv($out, ['check', 'employee_code', 'leave_type_id', 'reference', 'detail']);
            foreach ($findings as $f) {
                fputcsv($out, [$f['check'], $codes[$f['employee_id']] ?? '', $f['leave_type_id'], $f['reference'], $f['detail']]);
            }
            fclose($out);
            $this->line("  Findings written to {$path}");
        }

        return $findings->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
