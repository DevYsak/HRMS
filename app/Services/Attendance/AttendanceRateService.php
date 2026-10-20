<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Services\Payroll\PayableDaysService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * THE attendance percentage (Phase 5 policy, QA-F-079, approved 20 Oct 2026).
 *
 *     Attendance % = attended eligible days ÷ eligible working days × 100
 *
 * Days are classified once, by PayableDaysService (the payroll / attendance
 * classification): Present, Late and WFH attendance count as attended (a half
 * day ½); approved paid leave, weekly offs, holidays, days outside employment,
 * future days, today before it is attended and still-explainable absences are
 * not eligible; approved unpaid leave and a finalised unexplained absence stay
 * in the denominator and lower the rate. No screen computes its own rate.
 */
class AttendanceRateService
{
    public function __construct(private readonly PayableDaysService $days) {}

    /**
     * One employee over a window (clamped to today: future days are never eligible).
     *
     * @return array{attended: float, eligible: float, percent: ?int, exact: ?float, tracked: bool}
     */
    public function forEmployee(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay()->min(Carbon::today());

        if ($start->gt($end)) {
            return self::result(0.0, 0.0, false);
        }

        $period = $this->days->forPeriod($employee, $start, $end);

        return self::result((float) $period['attended_units'], (float) $period['attendance_eligible_units'], (bool) $period['attendance_tracked']);
    }

    /**
     * Many employees: each one's rate and the group rate (sum attended ÷ sum
     * eligible — a department or company figure weighs every eligible day once).
     *
     * @param  iterable<Employee>  $employees
     * @return array{employees: array<int, array{attended: float, eligible: float, percent: ?int, exact: ?float, tracked: bool}>, total: array{attended: float, eligible: float, percent: ?int, exact: ?float, tracked: bool}}
     */
    public function forEmployees(iterable $employees, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];
        foreach ($employees as $employee) {
            $rows[(int) $employee->id] = $this->forEmployee($employee, $from, $to);
        }

        return ['employees' => $rows, 'total' => self::combine($rows)];
    }

    /**
     * The group rate of already-computed employee rates.
     *
     * @param  iterable<array{attended: float, eligible: float, tracked: bool}>  $rates
     * @return array{attended: float, eligible: float, percent: ?int, exact: ?float, tracked: bool}
     */
    public static function combine(iterable $rates): array
    {
        $attended = 0.0;
        $eligible = 0.0;
        $tracked = false;
        foreach ($rates as $rate) {
            $attended += $rate['attended'];
            $eligible += $rate['eligible'];
            $tracked = $tracked || $rate['tracked'];
        }

        return self::result($attended, $eligible, $tracked);
    }

    /** @return array{attended: float, eligible: float, percent: ?int, exact: ?float, tracked: bool} */
    private static function result(float $attended, float $eligible, bool $tracked): array
    {
        $exact = $eligible > 0 ? round(min(100.0, $attended / $eligible * 100), 1) : null;

        return [
            'attended' => round($attended, 2),
            'eligible' => round($eligible, 2),
            'percent' => $exact !== null ? (int) round($exact) : null,
            'exact' => $exact,
            'tracked' => $tracked,
        ];
    }
}
