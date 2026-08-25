<?php

namespace App\Livewire\Payroll;

use App\Http\Controllers\PayslipController;
use App\Models\AuditLog;
use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;
use Livewire\WithPagination;

class MyPayslips extends Component
{
    use WithPagination;

    public bool $emailingSending = false;

    public ?int $emailingId = null;

    public string $filterYear = '';

    /** Quick period: all | last_3 | last_6 | fy | month | custom. */
    public string $filterPeriod = 'all';

    /** Month name (e.g. "January") when filterPeriod = month. */
    public string $filterMonth = '';

    /** Financial year start, e.g. 2026 → FY 2026-27, when filterPeriod = fy. */
    public string $filterFy = '';

    public string $rangeFrom = '';

    public string $rangeTo = '';

    /** @var array<int, int> Payslip ids ticked for a combined print. */
    public array $selected = [];

    /** Mirror of the controller cap so the view can label the limit. */
    public int $maxCombined = PayslipController::MAX_COMBINED_PAYSLIPS;

    // ── Filters ───────────────────────────────────────────────────────────────

    public function updatedFilterPeriod(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function resetFilters(): void
    {
        $this->filterPeriod = 'all';
        $this->filterMonth = '';
        $this->filterFy = '';
        $this->filterYear = '';
        $this->rangeFrom = '';
        $this->rangeTo = '';
        $this->selected = [];
        $this->resetPage();
    }

    /**
     * Month/year pairs the current filter covers, or null for "no restriction".
     *
     * payrolls stores a month *name* plus a separate year rather than a date, so
     * a range has to be expanded into the discrete periods it spans.
     *
     * @return array<int, array{month: string, year: int}>|null
     */
    private function resolvePeriods(): ?array
    {
        [$start, $end] = match ($this->filterPeriod) {
            'last_3' => [Carbon::now()->startOfMonth()->subMonths(2), Carbon::now()->endOfMonth()],
            'last_6' => [Carbon::now()->startOfMonth()->subMonths(5), Carbon::now()->endOfMonth()],
            // Indian financial year: 1 Apr → 31 Mar.
            'fy' => $this->filterFy !== ''
                ? [Carbon::create((int) $this->filterFy, 4, 1), Carbon::create((int) $this->filterFy + 1, 3, 31)]
                : [null, null],
            'month' => $this->filterMonth !== ''
                ? [
                    $m = Carbon::parse('1 '.$this->filterMonth.' '.($this->filterYear ?: Carbon::now()->year)),
                    $m->copy()->endOfMonth(),
                ]
                : [null, null],
            'custom' => ($this->rangeFrom !== '' && $this->rangeTo !== '')
                ? [Carbon::parse($this->rangeFrom)->startOfMonth(), Carbon::parse($this->rangeTo)->endOfMonth()]
                : [null, null],
            default => [null, null],
        };

        if (! $start || ! $end || $start->gt($end)) {
            return null;
        }

        $periods = [];
        for ($cursor = $start->copy()->startOfMonth(); $cursor->lte($end); $cursor->addMonth()) {
            $periods[] = ['month' => $cursor->format('F'), 'year' => (int) $cursor->year];
        }

        return $periods;
    }

    /**
     * Constrain a payslip query to the resolved periods.
     *
     * @param  array<int, array{month: string, year: int}>|null  $periods
     */
    private function applyPeriodFilter($query, ?array $periods)
    {
        if ($periods === null) {
            return $query;
        }

        return $query->whereHas('payroll', function ($p) use ($periods) {
            $p->where(function ($inner) use ($periods) {
                foreach ($periods as $period) {
                    $inner->orWhere(
                        fn ($x) => $x->where('month', $period['month'])->where('year', $period['year'])
                    );
                }
            });
        });
    }

    // ── Combined multi-month print ────────────────────────────────────────────

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * Hand the ticked payslips to the combined-print route.
     *
     * The controller re-checks ownership and the cap; this only spares the user
     * a round-trip when the selection is obviously unusable.
     */
    public function printSelected()
    {
        $ids = array_values(array_unique(array_map('intval', $this->selected)));

        if ($ids === []) {
            \Flux::toast('Select at least one payslip to print.', variant: 'danger');

            return null;
        }

        if (count($ids) > $this->maxCombined) {
            \Flux::toast("You can print at most {$this->maxCombined} payslips at once.", variant: 'danger');

            return null;
        }

        return $this->redirect(route('payroll.payslips.print-combined', ['ids' => $ids]));
    }

    // ── Email payslip to employee ─────────────────────────────────────────────

    public function emailPayslip(int $id): void
    {
        $employee = Auth::user()->employee;
        abort_unless($employee, 403);

        $slip = Payslip::with(['payroll', 'items', 'employee.user'])
            ->where('employee_id', $employee->id)
            ->findOrFail($id);

        $email = $slip->employee->user?->email;
        if (! $email) {
            \Flux::toast('No email address found for this employee.', variant: 'danger');

            return;
        }

        try {
            $this->emailingId = $id;

            $payslip = $slip;
            $pdf = Pdf::loadView('pdf.payslip', compact('payslip'))
                ->setPaper('a4', 'portrait');

            $month = $slip->payroll->month.' '.$slip->payroll->year;

            Mail::send([], [], function ($message) use ($email, $pdf, $month, $slip) {
                $message->to($email)
                    ->subject("Your Payslip for {$month}")
                    ->setBody(
                        "<p>Dear {$slip->employee->user->name},</p>".
                        "<p>Please find attached your salary slip for <strong>{$month}</strong>.</p>".
                        '<p>If you have any questions, please contact HR.</p><br><p>Regards,<br>HR Team</p>',
                        'text/html'
                    )
                    ->attachData($pdf->output(), "payslip_{$month}.pdf", ['mime' => 'application/pdf']);
            });

            AuditLog::record($slip, 'emailed', null, ['to' => $email], subjectEmployeeId: $slip->employee_id);
            \Flux::toast("Payslip emailed to {$email}.", variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast('Failed to send email: '.$e->getMessage(), variant: 'danger');
        } finally {
            $this->emailingId = null;
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render()
    {
        $employee = Auth::user()->employee;

        // The view drives its footer off paginator methods (firstItem/total/links),
        // so staff without an employee record need an empty *paginator* here —
        // a bare collection blows up on $payslips->firstItem().
        $empty = [
            'payslips' => new LengthAwarePaginator([], 0, 5, 1, ['path' => route('payroll.payslips')]),
            'latestPayslip' => null,
            'trendLabels' => [],
            'trendGross' => [],
            'trendNet' => [],
            'salaryRevisions' => [],
            'monthlyGross' => 0,
            'monthlyNet' => 0,
            'monthlyDeductions' => 0,
            'ctc' => 0,
            'payCycle' => null,
            'salaryComponents' => collect(),
            'periodTotals' => ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'months' => 0],
            'lastCreditedAt' => null,
        ];

        if (! $employee) {
            return view('livewire.payroll.my-payslips', $empty)->layout('layouts.app', ['title' => 'My Payslip']);
        }

        $periods = $this->resolvePeriods();

        $filtered = fn () => $this->applyPeriodFilter(
            Payslip::where('employee_id', $employee->id)
                ->whereIn('status', ['paid', 'draft'])
                ->when($this->filterYear, fn ($q) => $q->whereHas('payroll', fn ($q2) => $q2->where('year', $this->filterYear))),
            $periods,
        );

        $payslips = $filtered()
            ->with(['payroll', 'items'])
            ->orderByDesc('id')
            ->paginate(5)->withPath(route('payroll.payslips'));

        // Totals for the filtered window (not just the current page).
        $periodTotals = $filtered()
            ->selectRaw('COALESCE(SUM(gross_salary), 0) as gross')
            ->selectRaw('COALESCE(SUM(total_deductions), 0) as deductions')
            ->selectRaw('COALESCE(SUM(net_salary), 0) as net')
            ->selectRaw('COUNT(*) as months')
            ->first();

        $allPayslips = Payslip::where('employee_id', $employee->id)
            ->whereIn('status', ['paid', 'draft'])
            ->with(['payroll', 'items'])
            ->orderByDesc('id')
            ->get();

        $latestPayslip = $allPayslips->first();

        // Trend data
        $trendSlips = $allPayslips->take(6)->reverse()->values();
        $trendLabels = $trendSlips->map(fn ($s) => substr($s->payroll->month ?? '', 0, 3).' '.($s->payroll->year ?? ''))->toArray();
        $trendGross = $trendSlips->map(fn ($s) => (float) $s->gross_salary)->toArray();
        $trendNet = $trendSlips->map(fn ($s) => (float) $s->net_salary)->toArray();

        // Salary components
        $salaryComponents = $employee->salaries()->with('component')->get();
        $monthlyGross = (float) ($latestPayslip?->gross_salary ?? $salaryComponents->filter(fn ($s) => ($s->component?->type ?? '') === 'earning')->sum('amount'));
        $monthlyDeductions = (float) ($latestPayslip?->total_deductions ?? $salaryComponents->filter(fn ($s) => ($s->component?->type ?? '') === 'deduction')->sum('amount'));
        $monthlyNet = (float) ($latestPayslip?->net_salary ?? $monthlyGross - $monthlyDeductions);
        $ctc = round($monthlyGross * 12, 2);

        // Salary revision history
        $salaryRevisions = [];
        $prev = null;
        foreach ($allPayslips->reverse() as $slip) {
            $gross = (float) $slip->gross_salary;
            if ($prev === null || abs($gross - $prev) > 100) {
                $pct = $prev ? round((($gross - $prev) / $prev) * 100, 1) : 0;
                $salaryRevisions[] = [
                    'month' => ($slip->payroll->month ?? '').' '.($slip->payroll->year ?? ''),
                    'gross' => $gross,
                    'net' => (float) $slip->net_salary,
                    'change' => $pct,
                    'label' => $pct > 0 ? "{$pct}% Increment" : ($pct < 0 ? "{$pct}% Reduction" : 'Joining'),
                    'color' => $pct > 0 ? 'emerald' : ($pct < 0 ? 'red' : 'blue'),
                ];
                $prev = $gross;
            }
        }
        $salaryRevisions = array_reverse(array_slice($salaryRevisions, -4));

        return view('livewire.payroll.my-payslips', [
            'payslips' => $payslips,
            'latestPayslip' => $latestPayslip,
            'trendLabels' => $trendLabels,
            'trendGross' => $trendGross,
            'trendNet' => $trendNet,
            'salaryRevisions' => $salaryRevisions,
            'monthlyGross' => $monthlyGross,
            'monthlyNet' => $monthlyNet,
            'monthlyDeductions' => $monthlyDeductions,
            'ctc' => $ctc,
            'payCycle' => $employee->salary_cycle ?? null,
            'salaryComponents' => $salaryComponents,
            'periodTotals' => [
                'gross' => (float) ($periodTotals->gross ?? 0),
                'deductions' => (float) ($periodTotals->deductions ?? 0),
                'net' => (float) ($periodTotals->net ?? 0),
                'months' => (int) ($periodTotals->months ?? 0),
            ],
            'lastCreditedAt' => $latestPayslip?->payroll?->finance_approved_at,
        ])->layout('layouts.app', ['title' => 'My Payslip']);
    }
}
