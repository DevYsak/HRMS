<?php

namespace App\Services;

use App\Models\Employee;
use App\Services\Payroll\PayableDaysService;
use Illuminate\Support\Carbon;

/**
 * Leave Without Pay for one employee and window — now a thin view of
 * {@see PayableDaysService}, the one payable-days answer (Phase 4 policy).
 *
 * It used to keep its own rules: a whole request became LWP once its type's
 * balance was overdrawn (not just the excess), the balance was looked up by
 * calendar year instead of the July → June leave year, and absence was never
 * counted. Kept for callers that only need the LWP days; the payroll engine
 * reads PayableDaysService directly.
 */
class LwpService
{
    public function __construct(private readonly PayableDaysService $payableDays) {}

    /**
     * @param  float  $monthlyEarnings  structural earnings for the window, as the engine resolved them
     * @return array{days: float, deduction: float, items: array<int, array{name: string, amount: float, type: string}>}
     */
    public function calculate(Employee $employee, Carbon $from, Carbon $to, float $monthlyEarnings): array
    {
        $payable = $this->payableDays->forPeriod($employee, $from, $to);
        $days = (float) $payable['lop_days'];
        $rate = $payable['eligible_working_days'] > 0 ? $monthlyEarnings / $payable['eligible_working_days'] : 0.0;
        $deduction = round(min($rate * $days, $monthlyEarnings), 2);

        return [
            'days' => $days,
            'deduction' => $deduction,
            'items' => $days > 0 ? [['name' => 'LWP ('.rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.').' day'.($days != 1.0 ? 's' : '').')', 'amount' => $deduction, 'type' => 'deduction']] : [],
        ];
    }
}
