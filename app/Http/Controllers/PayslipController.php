<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class PayslipController extends Controller
{
    /** Most payslips an employee may combine into a single print. */
    public const MAX_COMBINED_PAYSLIPS = 6;

    /**
     * Stream several payslips as one PDF, newest first, one payslip per page.
     *
     * Only the signed-in employee's own payslips are eligible unless the viewer
     * can run payroll; the cap is enforced here rather than in the UI so a
     * hand-crafted request cannot pull an unbounded range.
     */
    public function downloadCombined(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_COMBINED_PAYSLIPS],
            'ids.*' => ['integer'],
        ], [
            'ids.max' => 'You can print at most '.self::MAX_COMBINED_PAYSLIPS.' payslips at once.',
            'ids.required' => 'Select at least one payslip to print.',
        ]);

        $user = auth()->user();

        $query = Payslip::with(['payroll', 'items', 'employee.user'])
            ->whereIn('id', $validated['ids']);

        // Employees are scoped to their own payslips; payroll staff may print anyone's.
        if (! $user->canRunPayroll()) {
            $employee = $user->employee;
            abort_unless($employee, 403);
            $query->where('employee_id', $employee->id);
        }

        // payrolls.month holds a month *name*, so order by a real date rather
        // than the string (which would sort "April" before "January").
        $payslips = $query->get()
            ->sortByDesc(fn (Payslip $p) => $this->payrollPeriod($p))
            ->values();

        abort_if($payslips->isEmpty(), 404, 'No payslips found for the selected months.');

        $pdf = Pdf::loadView('pdf.payslips-combined', ['payslips' => $payslips])
            ->setPaper('a4', 'portrait');

        $first = $payslips->last();
        $last = $payslips->first();
        $range = $payslips->count() === 1
            ? $first->payroll->month.'_'.$first->payroll->year
            : $first->payroll->month.'_'.$first->payroll->year.'-'.$last->payroll->month.'_'.$last->payroll->year;

        return $pdf->stream('payslips_'.$range.'.pdf');
    }

    /**
     * Sortable period for a payslip. Falls back to the epoch when the payroll
     * month is missing or unparseable so a bad row sorts last instead of throwing.
     */
    private function payrollPeriod(Payslip $payslip): int
    {
        $payroll = $payslip->payroll;

        if (! $payroll?->month || ! $payroll?->year) {
            return 0;
        }

        try {
            return (int) Carbon::parse("1 {$payroll->month} {$payroll->year}")->timestamp;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function download(Payslip $payslip)
    {
        // Ensure the user is either the employee who owns the payslip, a manager of the employee, HR Admin, or Finance/SuperAdmin
        if (! Gate::check('view', $payslip) && auth()->user()->id !== $payslip->employee->user_id && ! auth()->user()->canRunPayroll()) {
            abort(403, 'Unauthorized action.');
        }

        // If a stored PDF path exists, serve it directly from storage for performance and auditing
        if (! empty($payslip->pdf_path) && Storage::exists($payslip->pdf_path)) {
            $filename = 'payslip_'.$payslip->employee->employee_id.'_'.$payslip->payroll->month.'_'.$payslip->payroll->year.'_'.$payslip->payroll->cycle.'.pdf';

            return Storage::download($payslip->pdf_path, $filename);
        }

        // Generate PDF and stream inline (opens in browser tab)
        $pdf = Pdf::loadView('pdf.payslip', compact('payslip'));

        $filename = 'payslip_'.$payslip->employee->employee_id.'_'.$payslip->payroll->month.'_'.$payslip->payroll->year.'_'.$payslip->payroll->cycle.'.pdf';

        return $pdf->stream($filename);
    }
}
