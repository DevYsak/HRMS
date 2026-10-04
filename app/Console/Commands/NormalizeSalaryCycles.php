<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\SalaryCycle;
use Illuminate\Console\Command;

class NormalizeSalaryCycles extends Command
{
    protected $signature = 'hrms:normalize-salary-cycles
        {--dry-run : Report planned changes without writing anything}
        {--sync-from-form : Also move employees whose payroll run differs from the cycle chosen on their form}';

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

        $this->reconcileWithForm($dryRun, (bool) $this->option('sync-from-form'));

        return self::SUCCESS;
    }

    /**
     * Employees whose run key (salary_cycle) disagrees with the cycle HR
     * picked on their form (salary_cycle_id). Before the employee observer
     * kept the two in step, moving someone to Cycle B on screen left them in
     * the Cycle A run.
     *
     * Always listed; moved only with --sync-from-form. The move changes which
     * run pays them (Cycle A pays the 1st–last, Cycle B the 21st–20th), so
     * HR and Finance settle each one's transition month first.
     */
    private function reconcileWithForm(bool $dryRun, bool $sync): void
    {
        $keyBySlug = ['cycle-a' => 'cycle_a', 'cycle-b' => 'cycle_b'];
        $slugById = SalaryCycle::pluck('slug', 'id');
        $formKey = fn (Employee $employee): ?string => $keyBySlug[$slugById->get($employee->salary_cycle_id)] ?? null;

        $mismatched = Employee::with('user')->whereNotNull('salary_cycle_id')->get()
            ->filter(fn (Employee $employee) => $formKey($employee) !== null
                && (self::MAP[$employee->salary_cycle] ?? $employee->salary_cycle) !== $formKey($employee))
            ->values();

        if ($mismatched->isEmpty()) {
            $this->line('  Every employee is paid in the run their form names.');

            return;
        }

        $this->warn("  {$mismatched->count()} employee(s) are paid in a different run from the cycle on their form:");
        $this->table(['Employee', 'Code', 'Paid in (salary_cycle)', 'Form says'], $mismatched->map(fn (Employee $employee) => [
            $employee->user?->name ?? '#'.$employee->id,
            $employee->employee_code ?? '—',
            $employee->salary_cycle ?? 'NULL',
            $formKey($employee),
        ])->all());

        if ($dryRun || ! $sync) {
            $this->line('  Not changed. Settle each transition month with Finance, then re-run with --sync-from-form to move them.');

            return;
        }

        foreach ($mismatched as $employee) {
            $employee->forceFill(['salary_cycle' => $formKey($employee)])->save();
        }

        $this->info("  Moved {$mismatched->count()} employee(s) to the run their form names.");
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
