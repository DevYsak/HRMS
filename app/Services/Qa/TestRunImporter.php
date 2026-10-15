<?php

namespace App\Services\Qa;

use App\Enums\QaTestStatus;
use App\Models\QaTestCase;
use Illuminate\Validation\ValidationException;

/**
 * Reads an automated test summary — JUnit XML (Pest `--log-junit`, PHPUnit,
 * Playwright's junit reporter) or Pest's console summary ("Tests: 2 failed,
 * 438 passed") — into run counts. Failures whose name matches a test case
 * marked KNOWN BASELINE are counted as baseline, not as new regressions.
 */
class TestRunImporter
{
    /**
     * @return array{total: int, passed: int, failed: int, skipped: int, duration_seconds: ?int, failures: array<int, string>, known_baseline: int, baseline_matches: array<int, string>}
     */
    public function parse(string $contents): array
    {
        $contents = trim($contents);
        if ($contents === '') {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $parsed = str_starts_with($contents, '<') ? $this->junit($contents) : $this->consoleSummary($contents);

        $baseline = $this->baselineMatches($parsed['failures']);

        return [...$parsed, 'known_baseline' => count($baseline), 'baseline_matches' => $baseline];
    }

    /** @return array{total: int, passed: int, failed: int, skipped: int, duration_seconds: ?int, failures: array<int, string>} */
    private function junit(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            throw ValidationException::withMessages(['file' => 'This is not a readable JUnit XML file.']);
        }

        $total = $failed = $skipped = 0;
        $time = 0.0;
        $failures = [];

        foreach ($doc->xpath('//testcase') ?: [] as $case) {
            $total++;
            $time += (float) ($case['time'] ?? 0);
            $name = trim(((string) ($case['classname'] ?? $case['class'] ?? '')).' > '.((string) $case['name']), ' >');

            if (isset($case->failure) || isset($case->error)) {
                $failed++;
                $failures[] = $name;
            } elseif (isset($case->skipped)) {
                $skipped++;
            }
        }

        if ($total === 0) {
            throw ValidationException::withMessages(['file' => 'No test cases found in the JUnit file.']);
        }

        return [
            'total' => $total,
            'passed' => $total - $failed - $skipped,
            'failed' => $failed,
            'skipped' => $skipped,
            'duration_seconds' => (int) round($time),
            'failures' => $failures,
        ];
    }

    /** @return array{total: int, passed: int, failed: int, skipped: int, duration_seconds: ?int, failures: array<int, string>} */
    private function consoleSummary(string $text): array
    {
        $text = preg_replace('/\e\[[0-9;]*m/', '', $text);

        if (! preg_match('/Tests:\s+(.+?)\(/m', $text.'(', $line)) {
            throw ValidationException::withMessages(['file' => 'No "Tests:" summary line found.']);
        }

        $count = fn (string $word) => preg_match('/(\d+)\s+'.$word.'/', $line[1], $m) ? (int) $m[1] : 0;
        $failed = $count('failed');
        $passed = $count('passed');
        $skipped = $count('skipped') + $count('todos?') + $count('incomplete');

        preg_match_all('/FAILED\s+(.+?)\s*$/m', $text, $failedLines);
        preg_match('/Duration:\s+([\d.]+)s/', $text, $duration);

        return [
            'total' => $failed + $passed + $skipped,
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
            'duration_seconds' => isset($duration[1]) ? (int) round((float) $duration[1]) : null,
            'failures' => array_map('trim', $failedLines[1]),
        ];
    }

    /**
     * Codes of the KNOWN BASELINE test cases a failure matches (by the test
     * name in automated_reference).
     *
     * @param  array<int, string>  $failures
     * @return array<int, string>
     */
    private function baselineMatches(array $failures): array
    {
        $norm = fn (string $s) => preg_replace('/[^a-z0-9]+/', '', strtolower($s));
        $baseline = QaTestCase::query()->where('status', QaTestStatus::KnownBaseline->value)->whereNotNull('automated_reference')->get(['code', 'automated_reference']);

        $matched = [];
        foreach ($failures as $failure) {
            $failureKey = $norm($failure);
            foreach ($baseline as $case) {
                $testName = $norm((string) str($case->automated_reference)->afterLast('>'));
                if ($testName !== '' && str_contains($failureKey, $testName)) {
                    $matched[] = $case->code;
                    break;
                }
            }
        }

        return $matched;
    }
}
