<?php

namespace App\Console\Commands;

use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveLedgerBackfillService;
use Illuminate\Console\Command;

/**
 * Move existing leave balances onto the leave ledger (Phase 2A).
 *
 * Preview by default: classifies every unmigrated balance as SAFE,
 * NEEDS_HR_REVIEW or BLOCKED and writes the report (and optionally a CSV)
 * without changing anything. --apply migrates SAFE rows only;
 * --include-review also preserves NEEDS_HR_REVIEW rows as an opening
 * balance flagged for HR. BLOCKED rows are never migrated. No migration ever
 * changes a balance's totals.
 */
class LeaveLedgerBackfill extends Command
{
    protected $signature = 'leave:ledger-backfill
        {--year= : Leave year label to limit to, e.g. 2026/27}
        {--employee= : Employee id to limit to}
        {--csv= : Write the preview report to this CSV path}
        {--apply : Migrate the SAFE rows (otherwise preview only)}
        {--include-review : With --apply, also migrate NEEDS_HR_REVIEW rows as flagged opening balances}
        {--actor= : User id recorded as the actor (defaults to the first Super Admin)}';

    protected $description = 'Preview or apply the migration of leave balances onto the leave ledger';

    public function handle(LeaveLedgerBackfillService $backfill): int
    {
        $filters = ['employee_id' => $this->option('employee') ? (int) $this->option('employee') : null];

        if ($label = $this->option('year')) {
            $year = LeaveYear::where('label', $label)->first();
            if (! $year) {
                $this->error("No leave year labelled {$label}.");

                return self::FAILURE;
            }
            $filters['leave_year_id'] = $year->id;
        }

        $rows = $backfill->reportRows($backfill->preview($filters));

        $this->table(
            ['Employee', 'Type', 'Year', 'Stored avail.', 'Known CF', 'Known adj.', 'Known accrual', 'Known usage', 'Migration amt', 'Class', 'Reason'],
            $rows->map(fn (array $r) => [
                $r['employee'], $r['leave_type'], $r['leave_year'], $r['stored_available'], $r['known_carry_forward'],
                $r['known_adjustments'], $r['known_accrual'], $r['known_usage'],
                $r['migration_amount'].' ('.$r['migration_bucket'].')', $r['classification'], $r['reason'],
            ])->all(),
        );

        $counts = $rows->countBy('classification');
        $this->info(sprintf(
            'Checked %d balance(s): %d SAFE, %d NEEDS_HR_REVIEW, %d BLOCKED.',
            $rows->count(), $counts['SAFE'] ?? 0, $counts['NEEDS_HR_REVIEW'] ?? 0, $counts['BLOCKED'] ?? 0,
        ));

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $rows->all());
            $this->info("Report written to {$path}");
        }

        if (! $this->option('apply')) {
            $this->comment('Preview only — nothing was changed. Re-run with --apply to migrate the SAFE rows.');

            return self::SUCCESS;
        }

        $actor = $this->option('actor')
            ? User::findOrFail((int) $this->option('actor'))
            : User::where('role', 'super_admin')->orderBy('id')->firstOrFail();

        $result = $backfill->apply($filters, $actor, (bool) $this->option('include-review'));

        $this->info(sprintf(
            'Migrated %d (SAFE %d, NEEDS_HR_REVIEW %d). Skipped %d awaiting review, %d BLOCKED, %d failed.',
            $result['migrated'], $result['safe'], $result['needs_hr_review'], $result['skipped'], $result['blocked'], $result['failed'],
        ));

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['Employee', 'Employee Code', 'Leave Type', 'Leave Year', 'Stored Allocated', 'Stored Used', 'Stored Encashed',
            'Current Stored Balance', 'Known Carry Forward', 'Known Adjustments', 'Known Accrual', 'Known Comp-Off', 'Known Usage',
            'Calculated Opening/Migration Amount', 'Migration Bucket', 'Classification', 'Reason']);

        foreach ($rows as $r) {
            fputcsv($handle, [$r['employee'], $r['employee_code'], $r['leave_type'], $r['leave_year'], $r['stored_allocated'], $r['stored_used'],
                $r['stored_encashed'], $r['stored_available'], $r['known_carry_forward'], $r['known_adjustments'], $r['known_accrual'],
                $r['known_comp_off'], $r['known_usage'], $r['migration_amount'], $r['migration_bucket'], $r['classification'], $r['reason']]);
        }

        fclose($handle);
    }
}
