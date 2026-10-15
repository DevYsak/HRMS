<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Employee;
use App\Services\DataQuality\DataQualityAuditor;
use App\Services\DataQuality\DataQualityIssue;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Org structure & data-quality report. READ-ONLY: there is no --fix. It
 * runs inside a transaction that is always rolled back, on an in-memory
 * cache, so even an accidental write could not persist. Corrections are
 * for HR to make on the records themselves.
 */
class DataQualityReport extends Command
{
    protected $signature = 'hrms:data-quality
        {--department= : Department id or name}
        {--employee= : Employee id or employee code}
        {--severity= : critical | high | warning | info (that level and worse)}
        {--json : Print the full report as JSON}
        {--csv : Print the issues as CSV}';

    protected $description = 'Read-only org structure & data-quality audit (no --fix, no writes)';

    public function handle(): int
    {
        config(['cache.default' => 'array']);

        $severity = $this->option('severity');
        if ($severity !== null && ! in_array($severity, DataQualityAuditor::SEVERITIES, true)) {
            $this->error('--severity must be one of: '.implode(', ', DataQualityAuditor::SEVERITIES));

            return self::INVALID;
        }

        DB::beginTransaction();
        try {
            $auditor = app(DataQualityAuditor::class);
            $issues = $this->filter($auditor->issues());
            if ($issues === null) {
                return self::INVALID;
            }
            $counts = $auditor->checkedCounts();

            if ($this->option('json')) {
                $this->line(json_encode([
                    'checked' => $counts,
                    'summary' => $this->summary($issues),
                    'issues' => $issues->map->toArray()->values(),
                    'role_drift' => $auditor->roleDrift(),
                    'overrides' => $auditor->overrides(),
                    'structure' => $auditor->structure(),
                    'departments' => $auditor->departmentHealth(),
                    'managers' => $auditor->managerHealth(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            if ($this->option('csv')) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['severity', 'issue', 'area', 'nature', 'subject_type', 'subject_id', 'subject', 'department', 'message', 'suggested_action']);
                foreach ($issues as $issue) {
                    $row = $issue->toArray();
                    fputcsv($out, [$row['severity'], $row['issue'], $row['area'], $row['nature'], $row['subject_type'], $row['subject_id'], $row['subject'], $row['department'], $row['message'], $row['suggested_action']]);
                }
                fclose($out);

                return self::SUCCESS;
            }

            $summary = $this->summary($issues);
            $this->info('Org structure & data quality — read-only');
            $this->table(['Employees checked', 'Departments', 'Managers', 'Roles', 'Critical', 'High', 'Warnings', 'Info'], [[
                $counts['employees'], $counts['departments'], $counts['managers'], $counts['roles'],
                $summary['critical'], $summary['high'], $summary['warning'], $summary['info'],
            ]]);

            if ($issues->isEmpty()) {
                $this->info('No issues found.');

                return self::SUCCESS;
            }

            $this->table(['Severity', 'Issue', 'Subject', 'Department', 'Detail'], $issues->map(fn (DataQualityIssue $i) => [
                strtoupper($i->severity), $i->label(), $i->subjectLabel, $i->departmentName ?? '—', $i->message,
            ])->all());
            $this->line('Nothing was changed. Corrections are for HR; see People → Data Quality.');

            return self::SUCCESS;
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  Collection<int, DataQualityIssue>  $issues
     * @return Collection<int, DataQualityIssue>|null
     */
    private function filter(Collection $issues): ?Collection
    {
        if ($dept = $this->option('department')) {
            $department = ctype_digit((string) $dept) ? Department::find($dept) : Department::where('name', $dept)->first();
            if ($department === null) {
                $this->error("Department not found: {$dept}");

                return null;
            }
            $issues = $issues->where('departmentId', $department->id);
        }

        if ($emp = $this->option('employee')) {
            $employee = Employee::where('employee_code', $emp)->first() ?? (ctype_digit((string) $emp) ? Employee::find($emp) : null);
            if ($employee === null) {
                $this->error("Employee not found: {$emp}");

                return null;
            }
            $issues = $issues->filter(fn (DataQualityIssue $i) => $i->employeeId === $employee->id || ($i->subjectType === 'user' && $i->subjectId === $employee->user_id));
        }

        if ($severity = $this->option('severity')) {
            $rank = array_search($severity, DataQualityAuditor::SEVERITIES, true);
            $issues = $issues->filter(fn (DataQualityIssue $i) => array_search($i->severity, DataQualityAuditor::SEVERITIES, true) <= $rank);
        }

        return $issues->values();
    }

    /**
     * @param  Collection<int, DataQualityIssue>  $issues
     * @return array<string, int>
     */
    private function summary(Collection $issues): array
    {
        return collect(DataQualityAuditor::SEVERITIES)->mapWithKeys(fn ($s) => [$s => $issues->where('severity', $s)->count()])->all();
    }
}
