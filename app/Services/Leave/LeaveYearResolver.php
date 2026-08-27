<?php

namespace App\Services\Leave;

use App\Models\Company;
use App\Models\LeaveYear;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Which leave year a date falls in.
 *
 * The company's leave year runs 1 July to 30 June, which is why a calendar
 * year could never describe it. The start month and day are configuration on
 * the company, so a future change to (say) 1 April is a settings edit rather
 * than a migration.
 *
 * Years are created on demand and cached in the table, so a balance can point
 * at a row with a real start and end date instead of an integer that has to be
 * interpreted by whoever reads it.
 */
class LeaveYearResolver
{
    /** Used only when no company record exists at all. */
    private const FALLBACK_START_MONTH = 7;

    private const FALLBACK_START_DAY = 1;

    /** The leave year containing a date, created if it does not exist yet. */
    public function forDate(?CarbonInterface $date = null): LeaveYear
    {
        $date = $date ? Carbon::instance($date)->startOfDay() : Carbon::today();

        [$startsOn, $endsOn] = $this->boundsFor($date);

        return LeaveYear::firstOrCreate(
            ['starts_on' => $startsOn->toDateString(), 'ends_on' => $endsOn->toDateString()],
            ['label' => $this->labelFor($startsOn, $endsOn)],
        );
    }

    /**
     * The `leave_balances.year` integer for the leave year containing a date.
     *
     * This is the fix for a whole class of quiet error. Balances are keyed on a
     * legacy calendar-year integer, and code that wanted "this year's balance"
     * reached for now()->year — which is the calendar year, not the leave year.
     * With a 1 July start those disagree for six months of every year: 20 June
     * 2025 belongs to leave year 2024/25 (integer 2024), but now()->year said
     * 2025 and the days were deducted from the wrong year's balance.
     *
     * Anything touching a balance for a known leave date must resolve through
     * here rather than reading a clock.
     */
    public function legacyYearFor(?CarbonInterface $date = null): int
    {
        return $this->forDate($date)->legacyYear();
    }

    public function current(): LeaveYear
    {
        return $this->forDate();
    }

    /** The year after a given one — where carry-over lands. */
    public function next(LeaveYear $year): LeaveYear
    {
        return $this->forDate($year->ends_on->copy()->addDay());
    }

    public function previous(LeaveYear $year): LeaveYear
    {
        return $this->forDate($year->starts_on->copy()->subDay());
    }

    /**
     * The start and end dates of the leave year containing $date.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function boundsFor(CarbonInterface $date): array
    {
        [$month, $day] = $this->configuredStart();

        $startThisYear = Carbon::create($date->year, $month, $day)->startOfDay();
        $date = Carbon::instance($date)->startOfDay();

        // Before the anniversary, the employee is still in the year that began
        // the previous calendar year — e.g. 3 March 2027 belongs to 2026/27.
        $startsOn = $date->lt($startThisYear)
            ? $startThisYear->copy()->subYear()
            : $startThisYear;

        return [$startsOn, $startsOn->copy()->addYear()->subDay()->endOfDay()->startOfDay()];
    }

    /**
     * "2026/27" for a year that spans two calendar years, "2026" for one that
     * does not — a January-start company should not be labelled 2026/26.
     */
    private function labelFor(Carbon $startsOn, Carbon $endsOn): string
    {
        return $startsOn->year === $endsOn->year
            ? (string) $startsOn->year
            : $startsOn->year.'/'.substr((string) $endsOn->year, -2);
    }

    /** @return array{0: int, 1: int} */
    private function configuredStart(): array
    {
        return once(function (): array {
            $company = Company::query()->first();

            return [
                (int) ($company->leave_year_start_month ?? self::FALLBACK_START_MONTH),
                (int) ($company->leave_year_start_day ?? self::FALLBACK_START_DAY),
            ];
        });
    }
}
