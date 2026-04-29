<?php

namespace App\Livewire\Payroll;

use App\Models\Payroll;
use App\Services\PayrollService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Process extends Component
{
    public string $month;

    public int $year;

    /** cycle_a = 1st-31st | cycle_b = 21st-20th */
    public string $cycle = 'cycle_a';

    public ?Payroll $currentPayroll = null;

    public function mount(): void
    {
        $this->month = Carbon::now()->format('F');
        $this->year = Carbon::now()->year;
        $this->loadCurrentPayroll();
    }

    public function loadCurrentPayroll(): void
    {
        $this->currentPayroll = Payroll::where('month', $this->month)
            ->where('year', $this->year)
            ->where('cycle', $this->cycle)
            ->with(['payslips.employee.user'])
            ->first();
    }

    /** Run payroll for the selected cycle (A or B). */
    public function startProcessing(): void
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        if ($this->currentPayroll && $this->currentPayroll->status === 'finalized') {
            \Flux::toast('Payroll for this period and cycle is already finalized.', variant: 'danger');

            return;
        }

        if ($this->currentPayroll && $this->currentPayroll->status === 'pending_finance') {
            \Flux::toast('Payroll is pending finance approval for this period and cycle.', variant: 'danger');

            return;
        }

        try {
            app(PayrollService::class)->generateDraft($this->month, $this->year, $this->cycle, Auth::id());
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->loadCurrentPayroll();

        $cycleLabel = strtoupper(str_replace('_', ' ', $this->cycle));
        \Flux::toast("Payroll draft [{$cycleLabel}] generated for {$this->month} {$this->year}.");
    }

    /** Finalize and mark all payslips as paid for this cycle run. */
    public function finalize(): void
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        if (! $this->currentPayroll || $this->currentPayroll->status !== 'draft') {
            \Flux::toast('No draft payroll to finalize.', variant: 'danger');

            return;
        }

        app(PayrollService::class)->submitForFinanceApproval($this->currentPayroll);

        $this->loadCurrentPayroll();

        \Flux::toast('Payroll submitted for finance approval.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        return view('livewire.payroll.process')
            ->layout('layouts.app', ['title' => 'Run Payroll']);
    }
}
