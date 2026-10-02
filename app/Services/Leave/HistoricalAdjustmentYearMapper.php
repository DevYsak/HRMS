<?php

namespace App\Services\Leave;

use App\Models\LeaveBalanceAdjustment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives older historical-balance adjustments the leave year they were about.
 *
 * Before leave_year_id existed on leave_balance_adjustments, a historical
 * statement only recorded its year in the audit entry written alongside it
 * (leave.historical_balance_set). Its adjusted_at is the day HR typed it in —
 * usually inside the current year — so matching it to a year by date labels
 * the wrong year as "stated historically".
 *
 * The year is taken from that audit entry only: its leave_year_id when it
 * names an existing year, otherwise its label, otherwise its legacy start
 * year when exactly one leave year starts then. Anything else stays null —
 * never guessed — and the backfill treats the employee's balances of that
 * type as needing HR review.
 *
 * Plain query builder, no model events; safe to run any number of times
 * (only rows still missing a year are considered).
 */
class HistoricalAdjustmentYearMapper
{
    /**
     * @return array{mapped: int, unmapped: int}
     */
    public function run(): array
    {
        $result = ['mapped' => 0, 'unmapped' => 0];

        $years = DB::table('leave_years')->get(['id', 'label', 'starts_on']);

        DB::table('leave_balance_adjustments')
            ->where('source', 'historical')
            ->whereNull('leave_year_id')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $adjustment) use ($years, &$result) {
                $yearId = $this->yearFromAudit((int) $adjustment->id, $years);

                if ($yearId === null) {
                    $result['unmapped']++;

                    return;
                }

                DB::table('leave_balance_adjustments')
                    ->where('id', $adjustment->id)
                    ->whereNull('leave_year_id')
                    ->update(['leave_year_id' => $yearId]);

                $result['mapped']++;
            });

        return $result;
    }

    /**
     * @param  Collection<int, object>  $years
     */
    private function yearFromAudit(int $adjustmentId, $years): ?int
    {
        $audits = DB::table('audit_logs')
            ->where('auditable_type', LeaveBalanceAdjustment::class)
            ->where('auditable_id', $adjustmentId)
            ->where('action', 'leave.historical_balance_set')
            ->pluck('new_values');

        $candidates = $audits
            ->map(fn ($values) => $this->yearFromValues(is_string($values) ? (array) json_decode($values, true) : (array) $values, $years))
            ->filter()
            ->unique();

        // No audit entry, or entries that disagree: not confident.
        return $candidates->count() === 1 ? (int) $candidates->first() : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  Collection<int, object>  $years
     */
    private function yearFromValues(array $values, $years): ?int
    {
        if (isset($values['leave_year_id']) && $years->contains('id', (int) $values['leave_year_id'])) {
            return (int) $values['leave_year_id'];
        }

        if (isset($values['leave_year_label'])) {
            $byLabel = $years->where('label', (string) $values['leave_year_label']);
            if ($byLabel->count() === 1) {
                return (int) $byLabel->first()->id;
            }
        }

        if (isset($values['leave_year']) && is_numeric($values['leave_year'])) {
            $byStart = $years->filter(fn (object $y) => (int) substr((string) $y->starts_on, 0, 4) === (int) $values['leave_year']);
            if ($byStart->count() === 1) {
                return (int) $byStart->first()->id;
            }
        }

        return null;
    }
}
