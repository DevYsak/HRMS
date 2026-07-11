<?php

namespace App\Services\Increments;

use App\Models\Employee;
use App\Models\EmployeeScorecard;
use App\Models\IncrementCycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Layer 2 of the increment engine (v4 Phase E, spec Part 4.2): annual
 * performance score + department calibration.
 *
 * Annual raw score = weighted average of the quarterly composite scores
 * (EmployeeScorecard.final_score, 0–100 scale) for the 12 months before the
 * cycle's effective date. Calibration is z-score per department; departments
 * with fewer than 5 eligible employees map raw scores straight to bands
 * (z-scores are meaningless on tiny samples). Deviation from the spec's
 * 5-point fallback thresholds: this codebase scores 0–100, so the raw-band
 * cutoffs are the spec's values × 20 (90/80/60/40).
 */
class CalibrationService
{
    public const MIN_QUARTERS = 2;

    public const SMALL_DEPT_THRESHOLD = 5;

    /**
     * Annual raw score for one employee.
     *
     * @return array{score: ?float, quarters: int}
     */
    public function annualScore(Employee $employee, IncrementCycle $cycle): array
    {
        $windowEnd = Carbon::parse($cycle->effective_date);
        $windowStart = $windowEnd->copy()->subYear();

        $scorecards = EmployeeScorecard::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('final_score')
            ->whereHas('cycle', fn ($q) => $q
                ->where('end_date', '>=', $windowStart->toDateString())
                ->where('end_date', '<', $windowEnd->toDateString()))
            ->with('cycle')
            ->get()
            ->sortBy(fn (EmployeeScorecard $card) => $card->cycle->end_date)
            ->values()
            ->take(4);

        $quarters = $scorecards->count();

        if ($quarters < self::MIN_QUARTERS) {
            return ['score' => null, 'quarters' => $quarters];
        }

        // Quarter weights (default equal); pro-rate over available quarters
        // by renormalising the weights of the quarters that exist.
        $configured = $cycle->quarter_weights ?: array_fill(0, 4, 25);
        $weights = array_slice(array_pad($configured, 4, 25), 4 - $quarters);
        $weightSum = array_sum($weights) ?: 1;

        $weighted = 0.0;
        foreach ($scorecards as $i => $card) {
            $weighted += (float) $card->final_score * ($weights[$i] ?? 25);
        }

        return ['score' => round($weighted / $weightSum, 2), 'quarters' => $quarters];
    }

    /**
     * Calibrate a set of scored employees within one department.
     *
     * @param  Collection<int, array{employee: Employee, score: float}>  $scored
     * @return Collection<int, array{employee: Employee, score: float, z: ?float, band: string}>
     */
    public function calibrateDepartment(Collection $scored): Collection
    {
        if ($scored->count() < self::SMALL_DEPT_THRESHOLD) {
            return $scored->map(fn (array $row) => $row + [
                'z' => null,
                'band' => $this->rawBand($row['score']),
            ]);
        }

        $mean = $scored->avg('score');
        $sd = $this->standardDeviation($scored->pluck('score'), $mean);

        return $scored->map(function (array $row) use ($mean, $sd) {
            // Zero spread — everyone identical → everyone "Meets".
            $z = $sd > 0 ? round(($row['score'] - $mean) / $sd, 4) : 0.0;

            return $row + ['z' => $z, 'band' => $this->zBand($z)];
        });
    }

    /** z → calibration band (spec Part 4.2). */
    public function zBand(float $z): string
    {
        return match (true) {
            $z >= 1.0 => 'A',
            $z >= 0.3 => 'B',
            $z >= -0.3 => 'C',
            $z >= -1.0 => 'D',
            default => 'E',
        };
    }

    /** Raw 0–100 score → band for small departments (spec 5-point cutoffs × 20). */
    public function rawBand(float $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'E',
        };
    }

    private function standardDeviation(Collection $values, float $mean): float
    {
        $n = $values->count();

        if ($n < 2) {
            return 0.0;
        }

        $variance = $values->sum(fn ($v) => ($v - $mean) ** 2) / $n;

        return sqrt($variance);
    }
}
