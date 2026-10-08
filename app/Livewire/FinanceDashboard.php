<?php

namespace App\Livewire;

use App\Livewire\Concerns\RequiresPayrollModule;
use App\Models\Incentive;
use App\Models\IncrementCycle;
use App\Models\IncrementProposal;
use App\Models\LeaveEncashment;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Reimbursement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Finance dashboard: what is waiting on Finance and what it will cost.
 *
 *  - Payroll queue  — the selected month's runs per cycle, keyed on the
 *    payroll's own month/year (never created_at), and anything awaiting
 *    finance sign-off.
 *  - OT payable     — approved overtime not yet paid (OvertimeRecord
 *    amounts, the figure payroll pays — not an hours × flat-rate guess).
 *  - Incentives / reimbursements — pending approval, and approved for the
 *    month whether still open or already included in a run.
 *  - Encashments    — those at Finance's own stage (pending_finance).
 *  - Compensation   — net paid this month / last month / year to date from
 *    finalised payroll, and increment cycles waiting for finance approval.
 *
 * Read-only; every action happens on its own page.
 */
class FinanceDashboard extends Component
{
    use RequiresPayrollModule;

    /** Y-m */
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        $this->normaliseMonth();
    }

    public function updatedMonth(): void
    {
        $this->normaliseMonth();
    }

    private function normaliseMonth(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            $this->month = Carbon::now()->format('Y-m');
        }
    }

    public function render()
    {
        $user = Auth::user();
        $period = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
        $previous = $period->copy()->subMonthNoOverflow();

        $runs = Payroll::where('month', $period->format('F'))->where('year', $period->year)
            ->withCount('payslips')
            ->orderBy('cycle')
            ->get();

        // The oldest five; the count is the true total (sign-off is a queue).
        $awaitingQuery = Payroll::where('status', 'pending_finance')->orderBy('year')->orderBy('created_at');
        $awaitingFinanceCount = (clone $awaitingQuery)->count();
        $awaitingFinance = $awaitingQuery->limit(5)->get(['id', 'month', 'year', 'cycle', 'total_payout', 'processed_at']);

        $netFor = fn (Carbon $m) => (float) Payslip::whereHas('payroll', fn ($q) => $q->where('month', $m->format('F'))->where('year', $m->year)->where('status', 'finalized'))->sum('net_salary');

        $unpaidOt = fn () => OvertimeRecord::unpaid()->whereHas('otRequest', fn ($q) => $q->where('status', 'approved'));

        $approvedStates = ['approved', 'included'];

        return view('livewire.finance-dashboard', [
            'period' => $period,
            'runs' => $runs,
            'awaitingFinance' => $awaitingFinance,
            'awaitingFinanceCount' => $awaitingFinanceCount,
            'canApproveFinance' => $user->canApproveFinance(),
            'canRunPayroll' => $user->canRunPayroll(),
            'netThisMonth' => $netFor($period),
            'netLastMonth' => $netFor($previous),
            'netYearToDate' => (float) Payslip::whereHas('payroll', fn ($q) => $q->where('year', $period->year)->where('status', 'finalized'))->sum('net_salary'),
            'otPayable' => [
                'amount' => round((float) $unpaidOt()->sum('ot_amount'), 2),
                'hours' => round((float) $unpaidOt()->sum('ot_hours'), 2),
                'people' => $unpaidOt()->distinct()->count('employee_id'),
                'top' => $unpaidOt()
                    ->selectRaw('employee_id, SUM(ot_hours) as hours, SUM(ot_amount) as amount')
                    ->groupBy('employee_id')
                    ->orderByDesc('amount')
                    ->with('employee.user')
                    ->limit(5)
                    ->get(),
            ],
            'incentives' => [
                'pending' => Incentive::pending()->count(),
                'approved_amount' => (float) Incentive::where('month', $this->month)->whereIn('status', $approvedStates)->sum('amount'),
            ],
            'reimbursements' => [
                'pending' => Reimbursement::pending()->count(),
                'approved_amount' => (float) Reimbursement::where('month', $this->month)->whereIn('status', $approvedStates)->sum('amount'),
            ],
            'encashmentsAwaitingFinance' => LeaveEncashment::where('status', 'pending_finance')->count(),
            'incrementCycles' => IncrementCycle::where('status', 'proposed')
                ->withSum(['proposals as proposed_total' => fn ($q) => $q->whereIn('status', ['pending', 'draft'])], 'proposed_amount')
                ->withCount('proposals')
                ->orderBy('effective_date')
                ->get(),
            'heldIncrements' => IncrementProposal::where('status', 'pending')
                ->whereHas('cycle', fn ($q) => $q->whereIn('status', ['approved', 'applied']))
                ->count(),
        ])->layout('layouts.app', ['title' => 'Finance Dashboard']);
    }
}
