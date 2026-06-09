<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Console\Command;

class BackfillOtSource extends Command
{
    protected $signature = 'hrms:backfill-ot-source
                            {--dry-run : Preview changes without writing to DB}';

    protected $description = 'Backfill ot_tracking_source on employees and departments based on department name keywords';

    /**
     * Department name keywords → ot_tracking_source mapping.
     * Nexflow: IT, Dev, Development, Engineering, QA, Quality, Project
     * Hybrid: PM, Product, Management
     * Biometric: HR, Finance, Admin, Operations, everything else
     *
     * @var array<string, array<string>>
     */
    private array $sourceMap = [
        'nexflow' => ['it', 'dev', 'development', 'engineering', 'qa', 'quality', 'software', 'tech'],
        'hybrid' => ['pm', 'project', 'product', 'scrum'],
        'manual' => [],
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->info('--- DRY RUN --- No changes will be written.');
        }

        $departmentUpdates = $this->backfillDepartments($isDryRun);
        $employeeUpdates = $this->backfillEmployees($isDryRun);

        $this->info("Departments updated: {$departmentUpdates}");
        $this->info("Employees updated: {$employeeUpdates}");

        return self::SUCCESS;
    }

    private function backfillDepartments(bool $isDryRun): int
    {
        $updated = 0;

        Department::all()->each(function (Department $dept) use ($isDryRun, &$updated): void {
            $source = $this->resolveSource($dept->name);

            if ($source === $dept->default_ot_source) {
                return;
            }

            $this->line("  Department [{$dept->name}] → {$source}");

            if (! $isDryRun) {
                $dept->update(['default_ot_source' => $source]);
            }

            $updated++;
        });

        return $updated;
    }

    private function backfillEmployees(bool $isDryRun): int
    {
        $updated = 0;

        Employee::with('department')->get()->each(function (Employee $employee) use ($isDryRun, &$updated): void {
            $source = $employee->department
                ? $this->resolveSource($employee->department->name)
                : 'biometric';

            if ($source === $employee->ot_tracking_source) {
                return;
            }

            $name = $employee->employee_id ?? "ID:{$employee->id}";
            $this->line("  Employee [{$name}] → {$source}");

            if (! $isDryRun) {
                $employee->update(['ot_tracking_source' => $source]);
            }

            $updated++;
        });

        return $updated;
    }

    private function resolveSource(string $deptName): string
    {
        $lower = strtolower($deptName);

        foreach (['nexflow', 'hybrid'] as $source) {
            foreach ($this->sourceMap[$source] as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $source;
                }
            }
        }

        return 'biometric';
    }
}
