<?php

namespace App\Console\Commands;

use App\Enums\QaEnvironment;
use App\Models\QaPhase;
use App\Models\QaTestRun;
use App\Models\User;
use App\Services\Qa\QaTracker;
use App\Services\Qa\TestRunImporter;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Record an automated test run in the QA & Audit tracker from a JUnit XML
 * file (Pest --log-junit, PHPUnit, Playwright junit reporter) or a saved
 * Pest console summary. It only adds a qa_test_runs row — it never runs
 * tests and never touches application data.
 */
class QaImportRun extends Command
{
    protected $signature = 'qa:import-run
        {file : JUnit XML or Pest console output}
        {--name= : Run name (default: file name + date)}
        {--suite=pest : pest | phpunit | playwright}
        {--kind=targeted : full | security | ui | targeted | manual}
        {--environment=local_test_db : local | local_test_db | e2e | staging | production_read_only}
        {--commit= : Commit the run was made on}
        {--phase= : QA phase number}
        {--user= : Email of the person recording the run}';

    protected $description = 'Record a Pest / PHPUnit / Playwright run summary in the QA & Audit tracker';

    public function handle(TestRunImporter $importer, QaTracker $tracker): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $environment = QaEnvironment::tryFrom((string) $this->option('environment'));
        $suite = (string) $this->option('suite');
        $kind = (string) $this->option('kind');
        if ($environment === null || ! array_key_exists($suite, QaTestRun::SUITES) || ! array_key_exists($kind, QaTestRun::KINDS)) {
            $this->error('Unknown --environment, --suite or --kind.');

            return self::FAILURE;
        }

        try {
            $summary = $importer->parse((string) file_get_contents($path));
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $run = $tracker->recordRun([
            'name' => $this->option('name') ?: basename($path).' — '.now()->format('d M Y H:i'),
            'qa_phase_id' => $this->option('phase') ? QaPhase::where('number', (int) $this->option('phase'))->value('id') : null,
            'suite' => $suite,
            'kind' => $kind,
            'environment' => $environment->value,
            'commit' => $this->option('commit') ?: null,
            'total' => $summary['total'],
            'passed' => $summary['passed'],
            'failed' => $summary['failed'],
            'skipped' => $summary['skipped'],
            'known_baseline' => $summary['known_baseline'],
            'duration_seconds' => $summary['duration_seconds'],
            'notes' => $summary['failures'] === [] ? null : "Failures:\n".implode("\n", $summary['failures']),
        ], $this->option('user') ? User::where('email', $this->option('user'))->first() : null);

        $this->table(['Total', 'Passed', 'Failed', 'Skipped', 'Known baseline', 'New regressions', 'Result'], [[
            $run->total, $run->passed, $run->failed, $run->skipped, $run->known_baseline, $run->new_regressions, $run->resultLabel(),
        ]]);

        return $run->new_regressions > 0 ? self::FAILURE : self::SUCCESS;
    }
}
