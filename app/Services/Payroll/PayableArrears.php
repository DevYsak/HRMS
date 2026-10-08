<?php

namespace App\Services\Payroll;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Payslip lines for approved items carried into a later run (D8).
 *
 * An item approved after its own period's run had closed is paid by the next
 * open run, never dropped. On the payslip it gets its own "arrears" line
 * naming the periods it came from, so Finance can see what was carried.
 */
class PayableArrears
{
    /**
     * Split items into this period's line and an arrears line.
     *
     * @param  Collection<int, object>  $rows  each with `amount` and a period column
     * @return array<int, array{name: string, amount: float, type: string}>
     */
    public static function lines(string $label, Collection $rows, string $runPeriod, string $periodColumn = 'month'): array
    {
        [$current, $carried] = $rows->partition(fn ($row) => (string) $row->{$periodColumn} >= $runPeriod);

        $lines = [];

        $currentTotal = round((float) $current->sum('amount'), 2);
        if ($currentTotal > 0) {
            $lines[] = ['name' => $label, 'amount' => $currentTotal, 'type' => 'earning'];
        }

        $carriedTotal = round((float) $carried->sum('amount'), 2);
        if ($carriedTotal > 0) {
            $periods = $carried->pluck($periodColumn)->map(fn ($p) => (string) $p)->unique()->sort()->implode(', ');
            $lines[] = ['name' => "{$label} — arrears ({$periods})", 'amount' => $carriedTotal, 'type' => 'earning'];
        }

        return $lines;
    }

    /** The period ('Y-m') an overtime day belongs to. */
    public static function periodOf(\DateTimeInterface|string $date): string
    {
        return Carbon::parse($date)->format('Y-m');
    }
}
