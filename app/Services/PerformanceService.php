<?php

namespace App\Services;

use App\Models\PerformanceReview;
use Carbon\Carbon;

class PerformanceService
{
    public function validateConexusQuarterWindow(string $name, string $startDate, string $endDate): void
    {
        if (! preg_match('/\bQ([1-4])\b/i', $name, $matches)) {
            return;
        }

        $quarter = (int) $matches[1];
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        $fyStartYear = $start->month >= 7 ? $start->year : $start->year - 1;
        [$expectedStart, $expectedEnd] = $this->expectedQuarterRange($quarter, $fyStartYear);

        if (! $start->equalTo($expectedStart) || ! $end->equalTo($expectedEnd)) {
            throw new \DomainException(
                "Invalid Q{$quarter} window for Conexus FY ".($fyStartYear).'-'.($fyStartYear + 1).
                ". Expected {$expectedStart->toDateString()} to {$expectedEnd->toDateString()}."
            );
        }
    }

    public function lockReview(PerformanceReview $review): PerformanceReview
    {
        if ($review->status !== 'manager_reviewed') {
            throw new \DomainException('Only manager-reviewed records can be marked complete by HR.');
        }

        $review->update(['status' => 'locked']);

        return $review->fresh();
    }

    public function assertReviewEditable(PerformanceReview $review, string $expectedStatus): void
    {
        if ($review->status === 'locked') {
            throw new \DomainException('This review is locked by HR and cannot be edited.');
        }

        if ($review->status !== $expectedStatus) {
            throw new \DomainException("Review must be in {$expectedStatus} state.");
        }

        if ($review->cycle && $review->cycle->status === 'closed') {
            throw new \DomainException('Review cycle is closed.');
        }
    }

    protected function expectedQuarterRange(int $quarter, int $fyStartYear): array
    {
        return match ($quarter) {
            1 => [Carbon::create($fyStartYear, 7, 1), Carbon::create($fyStartYear, 9, 30)],
            2 => [Carbon::create($fyStartYear, 10, 1), Carbon::create($fyStartYear, 12, 31)],
            3 => [Carbon::create($fyStartYear + 1, 1, 1), Carbon::create($fyStartYear + 1, 3, 31)],
            4 => [Carbon::create($fyStartYear + 1, 4, 1), Carbon::create($fyStartYear + 1, 6, 30)],
        };
    }
}
