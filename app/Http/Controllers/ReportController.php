<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function payrollSummaryPdf(Request $request): Response
    {
        $month = (int) $request->integer('month', now()->month);
        $year = (int) $request->integer('year', now()->year);

        $payrolls = Payroll::with(['payslips.employee.user', 'processedBy', 'financeApprovedBy'])
            ->where('month', $month)
            ->where('year', $year)
            ->orderByDesc('created_at')
            ->get();

        $monthLabel = Carbon::create($year, $month, 1)->format('F Y');

        $pdf = Pdf::loadView('pdf.reports.payroll-summary', compact('payrolls', 'monthLabel', 'month', 'year'));

        return $pdf->download("payroll-summary-{$year}-{$month}.pdf");
    }

    public function attendanceSummaryCsv(Request $request): StreamedResponse
    {
        $month = (int) $request->integer('month', now()->month);
        $year = (int) $request->integer('year', now()->year);

        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $rows = Attendance::with('employee.user')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->orderBy('employee_id')
            ->get();

        $filename = "attendance-summary-{$year}-{$month}.csv";

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Employee ID', 'Employee Name', 'Date', 'Check In', 'Check Out',
                'Total Hours', 'Break Minutes', 'Late', 'Late Minutes',
                'Missing Checkout', 'Work Mode', 'Status',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->employee->employee_id ?? '',
                    $row->employee->user->name ?? '',
                    $row->date->toDateString(),
                    $row->check_in?->format('H:i') ?? '',
                    $row->check_out?->format('H:i') ?? '',
                    $row->total_hours ?? '',
                    $row->break_minutes ?? 0,
                    $row->is_late ? 'Yes' : 'No',
                    $row->late_minutes ?? 0,
                    $row->missing_checkout ? 'Yes' : 'No',
                    $row->work_mode ?? '',
                    $row->status ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function otRecordsCsv(Request $request): StreamedResponse
    {
        $month = (int) $request->integer('month', now()->month);
        $year = (int) $request->integer('year', now()->year);

        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $rows = OvertimeRecord::with('employee.user', 'otRequest')
            ->whereBetween('ot_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('ot_date')
            ->get();

        $filename = "ot-records-{$year}-{$month}.csv";

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Employee ID', 'Employee Name', 'OT Date', 'OT Hours',
                'OT Amount', 'Paid',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->employee->employee_id ?? '',
                    $row->employee->user->name ?? '',
                    $row->ot_date ?? '',
                    $row->ot_hours ?? '',
                    $row->ot_amount ?? '',
                    $row->is_paid ? 'Yes' : 'No',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
