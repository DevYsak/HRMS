<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\SalaryCycle;
use Illuminate\Console\Command;

class NormalizeSalaryCycles extends Command
{
    protected $signature = 'hrms:normalize-salary-cycles {--dry-run : Report planned changes without writing anything}';

    protected $description = "Normalize legacy employees.salary_cycle values (e.g. 'A'/'B') to 'cycle_a'/'cycle_b' as expected by PayrollService, and backfill salary_cycle_id from the salary_cycles table.";

    /** @var array<string, string> */
    private const MAP = [
        'A' => 'cycle_a',
        'a' => 'cycle_a',
        'Cycle A' => 'cycle_a',
        'B' => 'cycle_b',
        'b' => 'cycle_b',
        'Cycle B' => 'cycle_b',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $cycleIds = SalaryCycle::pluck('id', 'slug');
        $cycleAId = $cycleIds->get('cycle-a');
        $cycleBId = $cycleIds->get('cycle-b');

        $distinct = Employee::select('salary_cycle')->distinct()->pluck('salary_cycle');

        $this->info('Distinct salary_cycle values found: '.$distinct->map(fn ($v) => $v ?? 'NULL')->implode(', '));

        foreach ($distinct as $value) {
            if ($value === null) {
                continue;
            }

            if (in_array($value, ['cycle_a', 'cycle_b'], true)) {
                $this->line("  '{$value}' already normalized — skipping.");

                continue;
            }

            $normalized = self::MAP[$value] ?? null;

            if ($normalized === null) {
                $this->warn("  '{$value}' has no known mapping — left unchanged. Review manually.");

                continue;
            }

            $count = Employee::where('salary_cycle', $value)->count();
            $cycleId = $normalized === 'cycle_a' ? $cycleAId : $cycleBId;

            if ($dryRun) {
                $this->line("  Would update {$count} employee(s): salary_cycle '{$value}' -> '{$normalized}'".($cycleId ? ", salary_cycle_id -> {$cycleId}" : ''));

                continue;
            }

            $update = ['salary_cycle' => $normalized];

            if ($cycleId) {
                $update['salary_cycle_id'] = $cycleId;
            }

            Employee::where('salary_cycle', $value)->update($update);
            $this->info("  Updated {$count} employee(s): salary_cycle '{$value}' -> '{$normalized}'".($cycleId ? ", salary_cycle_id -> {$cycleId}" : ''));
        }

        $this->backfillCycleId('cycle_a', $cycleAId, $dryRun);
        $this->backfillCycleId('cycle_b', $cycleBId, $dryRun);

        return self::SUCCESS;
    }

    private function backfillCycleId(string $cycleValue, ?int $cycleId, bool $dryRun): void
    {
        if (! $cycleId) {
            return;
        }

        $query = Employee::where('salary_cycle', $cycleValue)->whereNull('salary_cycle_id');
        $count = $query->count();

        if ($count === 0) {
            return;
        }

        if ($dryRun) {
            $this->line("  Would backfill salary_cycle_id={$cycleId} for {$count} employee(s) with salary_cycle='{$cycleValue}'.");

            return;
        }

        $query->update(['salary_cycle_id' => $cycleId]);
        $this->info("  Backfilled salary_cycle_id={$cycleId} for {$count} employee(s) with salary_cycle='{$cycleValue}'.");
    }
}
