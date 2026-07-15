<?php

namespace App\Livewire\Payroll;

use App\Http\Controllers\PayslipController;
use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
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

    /** @var array<int, int> Payslip ids ticked for a combined print. */
    public array $selected = [];

    /** Mirror of the controller cap so the view can label the limit. */
    public int $maxCombined = PayslipController::MAX_COMBINED_PAYSLIPS;

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

        $empty = [
            'payslips' => collect(),
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
        ];

        if (! $employee) {
            return view('livewire.payroll.my-payslips', $empty)->layout('layouts.app', ['title' => 'My Payslip']);
        }

        $payslips = Payslip::where('employee_id', $employee->id)
            ->whereIn('status', ['paid', 'draft'])
            ->with(['payroll', 'items'])
            ->when($this->filterYear, fn ($q) => $q->whereHas('payroll', fn ($q2) => $q2->where('year', $this->filterYear)))
            ->orderByDesc('id')
            ->paginate(5)->withPath(route('payroll.payslips'));

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
        ])->layout('layouts.app', ['title' => 'My Payslip']);
    }
}
