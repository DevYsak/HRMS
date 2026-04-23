<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PayslipController extends Controller
{
    public function download(Payslip $payslip)
    {
        // Ensure the user is either the employee who owns the payslip, a manager of the employee, HR Admin, or Finance/SuperAdmin
        if (!Gate::check('view', $payslip) && auth()->user()->id !== $payslip->employee->user_id && !auth()->user()->canRunPayroll()) {
            abort(403, 'Unauthorized action.');
        }

        $pdf = Pdf::loadView('pdf.payslip', compact('payslip'));
        
        $filename = 'payslip_' . $payslip->employee->employee_id . '_' . $payslip->payroll->month . '_' . $payslip->payroll->year . '_' . $payslip->payroll->cycle . '.pdf';
        
        return $pdf->download($filename);
    }
}
