<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\PublicHoliday;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The one place that answers "is this a holiday for this person?".
 *
 * Two half-rules had grown up independently, and neither was right on its own:
 *
 *   attendance  — filtered by country, ignored branch/department scope
 *   leave       — honoured scope, ignored country
 *
 * So a UK employee in the Mumbai office got the wrong answer from both, in
 * opposite directions, and the same date could be a working day in one module
 * and a holiday in the next.
 *
 * A holiday applies to an employee when ALL of these hold:
 *
 *   active   is_active
 *   calendar country matches the employee's calendar (IN / UK)
 *   scope    office, department and explicit employee list all permit it
 *   date     it falls on the date in question
 *
 * Callers that need one employee use forEmployeeOn()/isHoliday(). Callers that
 * need many — reports, team calendars — fetch the range once with
 * holidaysInRange() and test each employee with appliesTo(), which keeps it to
 * a single query while still giving every employee their own answer.
 */
class HolidayResolver
{
    /** Office country values that mean the UK calendar. */
    private const UK_COUNTRIES = ['UK', 'UNITED KINGDOM', 'GB', 'GREAT BRITAIN'];

    public const DEFAULT_COUNTRY = 'IN';

    /**
     * The holiday calendar an employee follows.
     *
     * Office country first, since that is the deliberate setting. The shift
     * name is a fallback for employees whose office is unset but who work the
     * UK Sales window — imperfect, but it is the existing rule and changing it
     * would silently move people between calendars.
     */
    public function resolveCountry(?Employee $employee): string
    {
        if (! $employee) {
            return self::DEFAULT_COUNTRY;
        }

        $officeCountry = strtoupper((string) ($employee->office?->country ?? ''));
        if (in_array($officeCountry, self::UK_COUNTRIES, true)) {
            return 'UK';
        }

        if (str_contains(strtoupper((string) ($employee->shift?->name ?? '')), 'UK')) {
            return 'UK';
        }

        return self::DEFAULT_COUNTRY;
    }

    /**
     * The holiday that applies to this employee on this date, or null.
     *
     * This is the canonical predicate; everything else here is a convenience
     * over it.
     */
    public function forEmployeeOn(?Employee $employee, CarbonInterface $date): ?PublicHoliday
    {
        return $this->query($employee)
            ->whereDate('date', Carbon::instance($date)->toDateString())
            ->get()
            ->first(fn (PublicHoliday $holiday) => $this->appliesTo($holiday, $employee));
    }

    /** Whether a date is a holiday on this employee's calendar. */
    public function isHoliday(?Employee $employee, CarbonInterface $date): bool
    {
        return $this->forEmployeeOn($employee, $date) !== null;
    }

    /**
     * Why a date is (or is not) a holiday for this employee.
     *
     * Reports and support questions need the reasoning, not just the verdict —
     * "not your calendar" and "not your office" are very different answers to
     * "why was I marked absent?".
     *
     * @return array{is_holiday:bool, holiday:?PublicHoliday, calendar:string, name:?string, scope:string}
     */
    public function explain(?Employee $employee, CarbonInterface $date): array
    {
        $country = $this->resolveCountry($employee);
        $holiday = $this->forEmployeeOn($employee, $date);

        return [
            'is_holiday' => $holiday !== null,
            'holiday' => $holiday,
            'calendar' => $country,
            'name' => $holiday?->name,
            'scope' => match (true) {
                $holiday === null => 'none',
                $holiday->office_id !== null => 'office',
                $holiday->department_id !== null => 'department',
                ! empty($holiday->applicable_employee_ids) => 'employees',
                default => 'company',
            },
        ];
    }

    /** The next holiday on this employee's calendar, on or after $from. */
    public function nextHoliday(?Employee $employee, ?CarbonInterface $from = null): ?PublicHoliday
    {
        return $this->upcomingHolidays($employee, 1, $from)->first();
    }

    /**
     * The next few holidays on this employee's calendar.
     *
     * Scope is applied in PHP, so the query is over-fetched a little and then
     * trimmed — taking $limit in SQL could return fewer than $limit after
     * scope filtering.
     *
     * @return Collection<int, PublicHoliday>
     */
    public function upcomingHolidays(?Employee $employee, int $limit = 4, ?CarbonInterface $from = null): Collection
    {
        $start = $from ? Carbon::instance($from) : Carbon::today();

        return $this->query($employee)
            ->whereDate('date', '>=', $start->toDateString())
            ->orderBy('date')
            ->limit(max($limit * 3, $limit + 10))
            ->get()
            ->filter(fn (PublicHoliday $holiday) => $this->appliesTo($holiday, $employee))
            ->take($limit)
            ->values();
    }

    /**
     * Every active holiday in a range, across all calendars.
     *
     * For multi-employee views: fetch once, then ask appliesTo() per employee.
     * Using one employee's calendar for a whole report is exactly the bug this
     * class exists to stop.
     *
     * @return Collection<int, PublicHoliday>
     */
    public function holidaysInRange(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return PublicHoliday::query()
            ->where('is_active', true)
            ->whereBetween('date', [
                Carbon::instance($from)->toDateString(),
                Carbon::instance($to)->toDateString(),
            ])
            ->orderBy('date')
            ->get();
    }

    /**
     * Whether an already-loaded holiday applies to an employee.
     *
     * Country plus scope. With no employee — an unassigned account, or a
     * company-wide view — only the default calendar is assumed, and scope
     * cannot be evaluated, so scoped holidays are excluded rather than
     * guessed at.
     */
    public function appliesTo(PublicHoliday $holiday, ?Employee $employee): bool
    {
        if ($holiday->country !== $this->resolveCountry($employee)) {
            return false;
        }

        if (! $employee) {
            return $holiday->office_id === null
                && $holiday->department_id === null
                && empty($holiday->applicable_employee_ids);
        }

        return $holiday->appliesToEmployee($employee);
    }

    /**
     * Dates in a range that are holidays for this employee, keyed by Y-m-d.
     *
     * @return Collection<string, PublicHoliday>
     */
    public function keyedForEmployee(?Employee $employee, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->holidaysInRange($from, $to)
            ->filter(fn (PublicHoliday $holiday) => $this->appliesTo($holiday, $employee))
            ->keyBy(fn (PublicHoliday $holiday) => Carbon::parse($holiday->date)->toDateString());
    }

    /**
     * Active holidays on this employee's calendar, before scope is applied.
     *
     * @return Builder<PublicHoliday>
     */
    private function query(?Employee $employee): Builder
    {
        return PublicHoliday::query()
            ->where('is_active', true)
            ->where('country', $this->resolveCountry($employee));
    }
}
