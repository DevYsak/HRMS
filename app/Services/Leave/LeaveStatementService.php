<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leave history read from the ledger (Phase 2D/2E): the transaction
 * timeline and the month-wise statement. Never derived from the
 * leave_balances summary row — every figure is a sum of ledger entries, so
 * every balance change is explainable.
 *
 * Employee-visible output carries only what the employee may see: the
 * movement, its date, its days and its public reason. HR internal notes live
 * on the adjustment record, never in the ledger reason, and are not read here.
 */
class LeaveStatementService
{
    /** @var array<string, string> entry type => label */
    public const LABELS = [
        LeaveLedgerEntry::TYPE_BASE => 'Base Entitlement',
        LeaveLedgerEntry::TYPE_CARRY_FORWARD => 'Carry Forward',
        LeaveLedgerEntry::TYPE_ACCRUAL => 'Accrual',
        LeaveLedgerEntry::TYPE_ADD_ON => 'Add-On Leave',
        LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT => 'HR Adjustment (credit)',
        LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT => 'HR Adjustment (debit)',
        LeaveLedgerEntry::TYPE_OPENING => 'Opening Balance',
        LeaveLedgerEntry::TYPE_USAGE => 'Leave Taken',
        LeaveLedgerEntry::TYPE_ENCASHMENT => 'Encashment',
        LeaveLedgerEntry::TYPE_EXPIRY => 'Expired',
    ];

    /**
     * The ledger as a dated, explained timeline with a running balance.
     *
     * @param  array{leave_type_id?: int|null, month?: string|null, entry_type?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Collection<int, array{id: int, date: Carbon, leave_type: ?string, leave_type_id: int, entry_type: string, label: string, days: float, reason: ?string, expires_on: ?Carbon, running: float, reversal: bool}>
     */
    public function timeline(Employee $employee, LeaveYear $year, array $filters = []): Collection
    {
        $entries = LeaveLedgerEntry::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('leave_year_id', $year->id)
            ->when($filters['leave_type_id'] ?? null, fn ($q, $id) => $q->where('leave_type_id', $id))
            ->orderBy('effective_date')->orderBy('id')
            ->get();

        $running = [];

        $rows = $entries->map(function (LeaveLedgerEntry $e) use (&$running) {
            $running[$e->leave_type_id] = round(($running[$e->leave_type_id] ?? 0) + (float) $e->days, 2);

            return [
                'id' => $e->id,
                'date' => $e->effective_date->copy(),
                'leave_type' => $e->leaveType?->name,
                'leave_type_id' => $e->leave_type_id,
                'entry_type' => $e->entry_type,
                'label' => $this->label($e),
                'days' => round((float) $e->days, 2),
                'reason' => $e->reason,
                'expires_on' => $e->expires_on?->copy(),
                'running' => $running[$e->leave_type_id],
                'reversal' => $e->isReversal(),
            ];
        });

        return $rows
            ->when($filters['entry_type'] ?? null, fn (Collection $r, $t) => $r->where('entry_type', $t))
            ->when($filters['month'] ?? null, fn (Collection $r, $m) => $r->filter(fn ($row) => $row['date']->format('Y-m') === $m))
            ->when($filters['from'] ?? null, fn (Collection $r, $d) => $r->filter(fn ($row) => $row['date']->gte(Carbon::parse($d))))
            ->when($filters['to'] ?? null, fn (Collection $r, $d) => $r->filter(fn ($row) => $row['date']->lte(Carbon::parse($d))))
            ->values();
    }

    /**
     * Month by month for one leave type.
     *
     * The year opens at zero; every posting — including the 1 July credit and
     * carry forward — sits in its own column, so a month reads:
     *
     *   opening + current-year credits + carry forward + add-ons (Comp Off)
     *   − used − encashed − expired ± other/reconciliation = closing
     *
     * "Other / reconciliation" holds HR adjustments, migrated opening
     * balances, reversals other than a cancelled leave, and usage the HR
     * register proves only as a total (its dates were never recorded, so it
     * is not presented as leave taken in the month it was posted). A
     * cancelled leave stays under Used, as a negative.
     *
     * @return Collection<int, array{month: string, label: string, opening: float, current_credits: float, carry_forward: float, add_ons: float, used: float, encashed: float, expired: float, other: float, closing: float, credits: float, credit_lines: array<string, float>, other_debits: float}>
     */
    public function monthly(Employee $employee, LeaveType $type, LeaveYear $year, ?Carbon $until = null): Collection
    {
        $entries = LeaveLedgerEntry::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('leave_year_id', $year->id)
            ->orderBy('effective_date')->orderBy('id')
            ->get();

        $until = ($until ?? Carbon::today())->copy()->min($year->ends_on);

        $months = collect();
        $cursor = $year->starts_on->copy()->startOfMonth();
        $balance = 0.0;

        while ($cursor->lte($until)) {
            $key = $cursor->format('Y-m');
            $inMonth = $entries->filter(fn ($e) => $e->effective_date->format('Y-m') === $key);
            $column = fn (string $name) => round((float) $inMonth->filter(fn ($e) => $this->column($e) === $name)->sum('days'), 2);

            $opening = $balance;
            $balance = round($opening + (float) $inMonth->sum('days'), 2);
            $credits = $inMonth->filter(fn ($e) => (float) $e->days > 0);

            $months->push([
                'month' => $key,
                'label' => $cursor->format('F Y'),
                'opening' => $opening,
                'current_credits' => $column('current_credits'),
                'carry_forward' => $column('carry_forward'),
                'add_ons' => $column('add_ons'),
                // Debits shown as positive amounts taken off.
                'used' => -$column('used') + 0.0,
                'encashed' => -$column('encashed') + 0.0,
                'expired' => -$column('expired') + 0.0,
                'other' => $column('other'),
                'closing' => $balance,
                // Kept for callers that read the older shape.
                'credits' => round((float) $credits->sum('days'), 2),
                'credit_lines' => $credits->groupBy(fn ($e) => $this->label($e))->map(fn ($g) => round((float) $g->sum('days'), 2))->all(),
                'other_debits' => round(-(float) $inMonth->filter(fn ($e) => (float) $e->days < 0
                    && ! in_array($e->entry_type, [LeaveLedgerEntry::TYPE_USAGE, LeaveLedgerEntry::TYPE_EXPIRY], true))->sum('days'), 2) + 0.0,
            ]);

            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /** Which statement column a ledger entry belongs in. */
    private function column(LeaveLedgerEntry $e): string
    {
        // A cancelled or re-reviewed leave is still a usage movement.
        if ($e->entry_type === LeaveLedgerEntry::TYPE_USAGE && $e->isReversal() && $e->source_type !== LeaveRegisterReconciliationService::SOURCE_TYPE) {
            return 'used';
        }

        if ($e->isReversal()) {
            return 'other';
        }

        // The register proves only a total; its dates were never recorded.
        if ($e->entry_type === LeaveLedgerEntry::TYPE_USAGE && $e->source_type === LeaveRegisterReconciliationService::SOURCE_TYPE) {
            return 'other';
        }

        return match ($e->entry_type) {
            LeaveLedgerEntry::TYPE_BASE, LeaveLedgerEntry::TYPE_ACCRUAL => 'current_credits',
            LeaveLedgerEntry::TYPE_CARRY_FORWARD => 'carry_forward',
            LeaveLedgerEntry::TYPE_ADD_ON => 'add_ons',
            LeaveLedgerEntry::TYPE_USAGE => 'used',
            LeaveLedgerEntry::TYPE_ENCASHMENT => 'encashed',
            LeaveLedgerEntry::TYPE_EXPIRY => 'expired',
            default => 'other',
        };
    }

    private function label(LeaveLedgerEntry $e): string
    {
        $label = self::LABELS[$e->entry_type] ?? ucfirst(str_replace('_', ' ', $e->entry_type));

        if ($e->source_type === LeaveRegisterReconciliationService::SOURCE_TYPE && ! $e->isReversal()) {
            $label = match ($e->entry_type) {
                LeaveLedgerEntry::TYPE_BASE => 'Current-year CSL credit (HR register)',
                LeaveLedgerEntry::TYPE_CARRY_FORWARD => 'Carry Forward (HR register)',
                LeaveLedgerEntry::TYPE_USAGE => 'Leave taken per HR register (dates not recorded)',
                default => $label.' (HR register)',
            };
        }

        if ($e->entry_type === LeaveLedgerEntry::TYPE_ACCRUAL) {
            $label = 'Monthly Accrual';
        }

        if ($e->entry_type === LeaveLedgerEntry::TYPE_ADD_ON && ($e->meta['add_on_type'] ?? null) === 'comp_off_credit') {
            $label = 'Comp-Off Credit';
        }

        if ($e->entry_type === LeaveLedgerEntry::TYPE_EXPIRY && $e->source_type === 'ledger_credit') {
            $label = 'Carry Forward / Add-On Expiry';
        }

        if ($e->entry_type === LeaveLedgerEntry::TYPE_EXPIRY && $e->source_type === 'leave_rollover') {
            $label = 'Year-End Lapse';
        }

        if ($e->entry_type === LeaveLedgerEntry::TYPE_USAGE && $e->isReversal()) {
            return 'Leave Cancelled / Reversed';
        }

        return $e->isReversal() ? $label.' (reversed)' : $label;
    }
}
