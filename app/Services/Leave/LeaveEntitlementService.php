<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveYear;
use App\Models\PublicHoliday;
use App\Services\Attendance\HolidayResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * UK holiday entitlement, calculated from the employee's working pattern.
 *
 * The statutory minimum is 5.6 weeks of paid holiday. Weeks, not days: a
 * five-day employee gets 28 days from the same rule that gives a three-day
 * employee 16.8. Giving everybody 28 days would over-pay part-timers and, more
 * importantly, hide the rule that produced the number.
 *
 * Nothing here reads the employee's shift. A shift says when the working day
 * starts; entitlement depends on how many days a week are worked and which
 * ones, which is the working pattern. Two people on the same shift can hold
 * different entitlements and two people on different shifts can hold the same.
 *
 * @see https://www.gov.uk/holiday-entitlement-rights
 * @see https://www.acas.org.uk/checking-holiday-entitlement/bank-holidays-and-christmas
 */
class LeaveEntitlementService
{
    /**
     * The statutory cap, in weeks-equivalent days for a five-day week.
     *
     * Nobody accrues more than 28 days of *statutory* leave however many hours
     * they work; a contract may still add to it separately.
     */
    private const STATUTORY_MAX_DAYS = 28.0;

    /** Days per week assumed when nobody has said otherwise. */
    private const DEFAULT_DAYS_PER_WEEK = 5.0;

    public function __construct(private readonly HolidayResolver $holidays) {}

    /**
     * What this employee is entitled to for this leave year.
     */
    public function for(Employee $employee, LeaveYear $year): Entitlement
    {
        $policy = $this->policyFor($employee);

        return $employee->working_pattern === 'regular'
            ? $this->regular($employee, $year, $policy)
            : $this->irregular($employee, $year, $policy);
    }

    /**
     * Fixed-days workers: weeks × days per week, pro-rated for part of a year.
     */
    private function regular(Employee $employee, LeaveYear $year, LeavePolicy $policy): Entitlement
    {
        $daysPerWeek = $this->daysPerWeek($employee);
        $factor = $this->proRataFactor($employee, $year);

        $statutory = $this->cap((float) $policy->statutory_weeks * $daysPerWeek, $daysPerWeek) * $factor;
        $contractual = (float) $policy->contractual_additional_weeks * $daysPerWeek * $factor;

        $bankHolidays = $policy->bankHolidaysCountTowardEntitlement()
            ? $this->bankHolidaysOnWorkingDays($employee, $year)
            : 0.0;

        $assumed = ! $this->hasRecordedPattern($employee);

        $explanation = sprintf(
            '%s weeks x %s days/week = %s days statutory%s%s%s',
            rtrim(rtrim((string) $policy->statutory_weeks, '0'), '.'),
            rtrim(rtrim((string) $daysPerWeek, '0'), '.'),
            round($statutory, 2),
            $factor < 1 ? sprintf(' (pro-rated to %s of the year)', round($factor, 4)) : '',
            $bankHolidays > 0 ? sprintf('; %s bank holiday(s) counted within it', $bankHolidays) : '',
            $assumed ? ' — ASSUMED: no working pattern on record, defaulted to Mon-Fri. Not verified employee data.' : '',
        );

        return new Entitlement(
            statutoryDays: round($statutory, 2),
            contractualDays: round($contractual, 2),
            bankHolidayDays: $bankHolidays,
            proRataFactor: round($factor, 4),
            method: 'regular',
            explanation: $explanation,
            patternAssumed: $assumed,
        );
    }

    /**
     * Irregular-hours and part-year workers.
     *
     * For leave years beginning on or after 1 April 2024 these accrue 12.07%
     * of the hours worked in each pay period rather than a fixed number of
     * days. Hours worked are not known to this service, so the contracted
     * weekly hours across the employed portion of the year are used as the
     * basis; a payroll-fed hours figure can be passed in later without the
     * shape of this changing.
     *
     * @see https://www.acas.org.uk/irregular-hours-and-part-year-workers
     */
    private function irregular(Employee $employee, LeaveYear $year, LeavePolicy $policy): Entitlement
    {
        $rate = (float) $policy->irregular_accrual_rate;
        $hoursPerWeek = (float) ($employee->contracted_hours_per_week ?: 0);
        $weeksEmployed = $this->weeksEmployedIn($employee, $year);

        $hoursWorked = $hoursPerWeek * $weeksEmployed;
        $accruedHours = $hoursWorked * $rate;

        // Hours become days at the employee's own average day length, so a
        // six-hour day and an eight-hour day both convert honestly.
        $hoursPerDay = $this->hoursPerDay($employee);
        $statutory = $hoursPerDay > 0 ? $accruedHours / $hoursPerDay : 0.0;

        $statutory = $this->cap($statutory, $this->daysPerWeek($employee));
        $contractual = (float) $policy->contractual_additional_weeks * $this->daysPerWeek($employee)
            * ($weeksEmployed / 52);

        $bankHolidays = $policy->bankHolidaysCountTowardEntitlement()
            ? $this->bankHolidaysOnWorkingDays($employee, $year)
            : 0.0;

        return new Entitlement(
            statutoryDays: round($statutory, 2),
            contractualDays: round($contractual, 2),
            bankHolidayDays: $bankHolidays,
            proRataFactor: round($weeksEmployed / 52, 4),
            method: 'irregular_hours',
            patternAssumed: ! $this->hasRecordedPattern($employee),
            explanation: sprintf(
                '%s%% of %s hours worked = %s hours, at %s hours/day = %s days',
                round($rate * 100, 2),
                round($hoursWorked, 2),
                round($accruedHours, 2),
                $hoursPerDay,
                round($statutory, 2),
            ),
        );
    }

    /**
     * Bank holidays falling on days this employee actually works.
     *
     * Somebody who works Tuesday to Saturday does not lose a day because a
     * bank holiday fell on a Monday — it was never a working day for them, so
     * there is nothing to deduct.
     */
    public function bankHolidaysOnWorkingDays(Employee $employee, LeaveYear $year): float
    {
        $workingDays = $this->workingWeekdays($employee);

        return (float) $this->holidays
            ->holidaysInRange($year->starts_on, $year->ends_on)
            ->filter(fn (PublicHoliday $holiday) => $this->holidays->appliesTo($holiday, $employee))
            ->filter(fn (PublicHoliday $holiday) => in_array(
                Carbon::parse($holiday->date)->dayOfWeekIso,
                $workingDays,
                true,
            ))
            ->count();
    }

    /**
     * Whether a specific date is a working day for this employee.
     *
     * Used by the leave engine so a bank holiday on a non-working day is
     * neither deducted nor treated as absence.
     */
    public function isWorkingDay(Employee $employee, CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->workingWeekdays($employee), true);
    }

    /**
     * The proportion of the leave year the employee is employed for.
     *
     * A 1 July joiner in a 1 July year gets 1.0; someone joining halfway
     * through gets roughly 0.5; a leaver is cut off at their last working day.
     */
    public function proRataFactor(Employee $employee, LeaveYear $year): float
    {
        $start = $this->employmentStart($employee, $year);
        $end = $this->employmentEnd($employee, $year);

        if ($start->gt($end)) {
            return 0.0;
        }

        $employedDays = (int) $start->diffInDays($end) + 1;

        return min(1.0, $employedDays / $year->lengthInDays());
    }

    private function weeksEmployedIn(Employee $employee, LeaveYear $year): float
    {
        return $this->proRataFactor($employee, $year) * 52;
    }

    /** The later of the year's start and the employee's joining date. */
    private function employmentStart(Employee $employee, LeaveYear $year): CarbonInterface
    {
        $joined = $employee->joining_date ? Carbon::parse($employee->joining_date)->startOfDay() : null;

        return $joined && $joined->gt($year->starts_on) ? $joined : $year->starts_on->copy();
    }

    /** The earlier of the year's end and the employee's last working day. */
    private function employmentEnd(Employee $employee, LeaveYear $year): CarbonInterface
    {
        $lastDay = $employee->exitRecord?->last_working_day
            ? Carbon::parse($employee->exitRecord->last_working_day)->startOfDay()
            : null;

        return $lastDay && $lastDay->lt($year->ends_on) ? $lastDay : $year->ends_on->copy();
    }

    /**
     * Statutory leave is capped at 28 days for a five-day week, and
     * proportionately below that for shorter weeks — the cap scales with the
     * pattern rather than being a flat 28 for everyone.
     */
    private function cap(float $days, float $daysPerWeek): float
    {
        $cap = self::STATUTORY_MAX_DAYS * (min($daysPerWeek, 5.0) / 5.0);

        return min($days, $cap);
    }

    /**
     * Whether this employee has a working pattern on file at all.
     *
     * When they do not, the entitlement below is computed from an assumed
     * Monday-to-Friday week. That figure is a placeholder and is flagged as
     * one, because a number nobody knows to doubt is the most dangerous kind.
     */
    public function hasRecordedPattern(Employee $employee): bool
    {
        return $employee->working_days_per_week !== null
            || (is_array($employee->working_days) && $employee->working_days !== []);
    }

    private function daysPerWeek(Employee $employee): float
    {
        if ($employee->working_days_per_week !== null) {
            return (float) $employee->working_days_per_week;
        }

        $weekdays = $employee->working_days;

        return is_array($weekdays) && $weekdays !== []
            ? (float) count($weekdays)
            : self::DEFAULT_DAYS_PER_WEEK;
    }

    /** @return array<int, int> ISO weekday numbers, 1 = Monday. */
    private function workingWeekdays(Employee $employee): array
    {
        $weekdays = $employee->working_days;

        if (is_array($weekdays) && $weekdays !== []) {
            return array_map('intval', $weekdays);
        }

        // No pattern recorded: assume Monday to Friday, the company norm.
        return [1, 2, 3, 4, 5];
    }

    private function hoursPerDay(Employee $employee): float
    {
        $hours = (float) ($employee->contracted_hours_per_week ?: 0);
        $days = $this->daysPerWeek($employee);

        return $days > 0 && $hours > 0 ? round($hours / $days, 2) : 8.0;
    }

    private function policyFor(Employee $employee): LeavePolicy
    {
        return $employee->leavePolicy
            ?? LeavePolicy::default()
            ?? new LeavePolicy([
                'statutory_weeks' => 5.60,
                'contractual_additional_weeks' => 0,
                'bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_ADDITIONAL,
                'irregular_accrual_rate' => 0.1207,
            ]);
    }
}
