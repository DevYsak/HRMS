<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\PerformanceReview;
use App\Models\PipRecord;
use App\Models\PromotionRecommendation;
use App\Models\WarningLetter;
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
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('work_date')
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
                    $row->work_date?->toDateString() ?? '',
                    $row->ot_hours ?? '',
                    $row->ot_amount ?? '',
                    $row->is_paid ? 'Yes' : 'No',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function performanceSummaryCsv(Request $request): StreamedResponse
    {
        $rows = PerformanceReview::with(['employee.user', 'employee.department', 'employee.jobTitle', 'employee.employmentType', 'performanceCycle'])
            ->when($request->department_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->designation_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('job_title_id', $request->designation_id)))
            ->when($request->employment_type_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employment_type_id', $request->employment_type_id)))
            ->when($request->from, fn ($q) => $q->whereDate('submitted_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('submitted_at', '<=', $request->to))
            ->latest('submitted_at')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'Designation', 'Cycle', 'Status', 'Final Score', 'Grade', 'Submitted At']);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r->employee->employee_id ?? '',
                    $r->employee->user->name ?? '',
                    $r->employee->department?->name ?? '',
                    $r->employee->jobTitle?->name ?? '',
                    $r->performanceCycle?->name ?? '',
                    $r->statusLabel(),
                    $r->final_score ?? '',
                    $r->gradeLabel(),
                    $r->submitted_at?->toDateString() ?? '',
                ]);
            }
            fclose($handle);
        }, 'performance-summary-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function kpiSummaryCsv(Request $request): StreamedResponse
    {
        $rows = EmployeeKpi::with(['employee.user', 'employee.department', 'employee.jobTitle', 'component', 'cycle'])
            ->when($request->department_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->designation_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('job_title_id', $request->designation_id)))
            ->when($request->employment_type_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employment_type_id', $request->employment_type_id)))
            ->when($request->from, fn ($q) => $q->whereDate('due_date', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('due_date', '<=', $request->to))
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'KPI Component', 'Target', 'Achieved', 'Progress %', 'Status', 'Due Date']);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r->employee->employee_id ?? '',
                    $r->employee->user->name ?? '',
                    $r->employee->department?->name ?? '',
                    $r->component?->name ?? '',
                    $r->target_value ?? '',
                    $r->achieved_value ?? '',
                    $r->progress_percent ?? '',
                    ucfirst(str_replace('_', ' ', $r->status)),
                    $r->due_date?->toDateString() ?? '',
                ]);
            }
            fclose($handle);
        }, 'kpi-summary-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function departmentPerformanceCsv(Request $request): StreamedResponse
    {
        $rows = PerformanceReview::with(['employee.department', 'employee.user', 'performanceCycle'])
            ->when($request->department_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->from, fn ($q) => $q->whereDate('submitted_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('submitted_at', '<=', $request->to))
            ->whereNotNull('final_score')
            ->latest('submitted_at')
            ->get()
            ->groupBy(fn ($r) => $r->employee->department?->name ?? 'Unassigned');

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Department', 'Total Reviews', 'Avg Score', 'Highest Score', 'Lowest Score']);
            foreach ($rows as $dept => $reviews) {
                fputcsv($handle, [
                    $dept,
                    $reviews->count(),
                    round($reviews->avg('final_score'), 2),
                    $reviews->max('final_score'),
                    $reviews->min('final_score'),
                ]);
            }
            fclose($handle);
        }, 'department-performance-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function promotionPipelineCsv(Request $request): StreamedResponse
    {
        $rows = PromotionRecommendation::with(['employee.user', 'employee.department', 'employee.jobTitle', 'recommendedBy'])
            ->when($request->department_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->employment_type_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employment_type_id', $request->employment_type_id)))
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'Type', 'Current Role', 'Proposed Role', 'Status', 'Recommended By', 'Effective Date']);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r->employee->employee_id ?? '',
                    $r->employee->user->name ?? '',
                    $r->employee->department?->name ?? '',
                    $r->recommendationTypeLabel(),
                    $r->current_role ?? '',
                    $r->proposed_role ?? '',
                    ucfirst(str_replace('_', ' ', $r->status)),
                    $r->recommendedBy?->name ?? '',
                    $r->effective_date?->toDateString() ?? '',
                ]);
            }
            fclose($handle);
        }, 'promotion-pipeline-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function warningLetterReportCsv(Request $request): StreamedResponse
    {
        $rows = WarningLetter::with(['employee.user', 'employee.department', 'issuedBy'])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->employment_type_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employment_type_id', $request->employment_type_id)))
            ->when($request->from, fn ($q) => $q->whereDate('issue_date', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('issue_date', '<=', $request->to))
            ->latest('issue_date')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'Warning Type', 'Reason', 'Issue Date', 'Status', 'Issued By', 'Acknowledged']);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r->employee->employee_id ?? '',
                    $r->employee->user->name ?? '',
                    $r->employee->department?->name ?? '',
                    $r->warningTypeLabel(),
                    $r->reason,
                    $r->issue_date?->toDateString() ?? '',
                    ucfirst($r->status),
                    $r->issuedBy?->name ?? '',
                    $r->isAcknowledged() ? 'Yes' : 'No',
                ]);
            }
            fclose($handle);
        }, 'warning-letter-report-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function pipProgressReportCsv(Request $request): StreamedResponse
    {
        $rows = PipRecord::with(['employee.user', 'employee.department', 'manager', 'goals'])
            ->when($request->department_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->employment_type_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employment_type_id', $request->employment_type_id)))
            ->when($request->from, fn ($q) => $q->whereDate('start_date', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('end_date', '<=', $request->to))
            ->latest('start_date')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'Manager', 'Start Date', 'End Date', 'Status', 'Goals', 'Avg Progress %', 'Outcome']);
            foreach ($rows as $r) {
                $avgProgress = $r->goals->avg('progress_percent') ?? 0;
                fputcsv($handle, [
                    $r->employee->employee_id ?? '',
                    $r->employee->user->name ?? '',
                    $r->employee->department?->name ?? '',
                    $r->manager?->name ?? '',
                    $r->start_date?->toDateString() ?? '',
                    $r->end_date?->toDateString() ?? '',
                    ucfirst($r->status),
                    $r->goals->count(),
                    round($avgProgress, 1),
                    $r->outcome ? ucfirst($r->outcome) : '—',
                ]);
            }
            fclose($handle);
        }, 'pip-progress-report-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function leaveUtilizationCsv(Request $request): StreamedResponse
    {
        $year = (int) $request->integer('year', now()->year);

        $rows = LeaveRequest::with(['employee.user', 'employee.department', 'employee.jobTitle', 'employee.employmentType', 'leaveType'])
            ->whereYear('start_date', $year)
            ->whereIn('status', ['approved'])
            ->when($request->department_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->employment_type_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employment_type_id', $request->employment_type_id)))
            ->latest('start_date')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Status']);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r->employee->employee_id ?? '',
                    $r->employee->user->name ?? '',
                    $r->employee->department?->name ?? '',
                    $r->leaveType?->name ?? '',
                    $r->start_date?->toDateString() ?? '',
                    $r->end_date?->toDateString() ?? '',
                    $r->total_days ?? '',
                    ucfirst($r->status),
                ]);
            }
            fclose($handle);
        }, "leave-utilization-{$year}.csv", ['Content-Type' => 'text/csv']);
    }

    public function leaveEncashmentReportCsv(Request $request): StreamedResponse
    {
        $rows = LeaveEncashment::with(['employee.user', 'employee.department', 'leaveType'])
            ->when($request->department_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'Leave Type', 'Days', 'Amount', 'Status', 'Requested On']);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r->employee->employee_id ?? '',
                    $r->employee->user->name ?? '',
                    $r->employee->department?->name ?? '',
                    $r->leaveType?->name ?? '',
                    $r->days ?? '',
                    $r->amount ?? '',
                    ucfirst($r->status),
                    $r->created_at?->toDateString() ?? '',
                ]);
            }
            fclose($handle);
        }, 'leave-encashment-report-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function attendanceComplianceCsv(Request $request): StreamedResponse
    {
        $month = (int) $request->integer('month', now()->month);
        $year = (int) $request->integer('year', now()->year);

        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $rows = Attendance::with(['employee.user', 'employee.department', 'employee.employmentType'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($request->department_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->employment_type_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employment_type_id', $request->employment_type_id)))
            ->orderBy('date')
            ->get()
            ->groupBy('employee_id');

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'Total Days', 'Present', 'Late', 'Missing Checkout', 'Unauthorized Absence']);
            foreach ($rows as $empId => $records) {
                $emp = $records->first()->employee;
                fputcsv($handle, [
                    $emp->employee_id ?? '',
                    $emp->user->name ?? '',
                    $emp->department?->name ?? '',
                    $records->count(),
                    $records->where('status', 'present')->count(),
                    $records->where('is_late', true)->count(),
                    $records->where('missing_checkout', true)->count(),
                    $records->where('status', 'unauthorized_absence')->count(),
                ]);
            }
            fclose($handle);
        }, "attendance-compliance-{$year}-{$month}.csv", ['Content-Type' => 'text/csv']);
    }

    public function employeeLifecycleCsv(Request $request): StreamedResponse
    {
        $rows = Employee::with(['user', 'department', 'jobTitle', 'employmentType'])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->designation_id, fn ($q) => $q->where('job_title_id', $request->designation_id))
            ->when($request->employment_type_id, fn ($q) => $q->where('employment_type_id', $request->employment_type_id))
            ->when($request->from, fn ($q) => $q->whereDate('joining_date', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('joining_date', '<=', $request->to))
            ->latest('joining_date')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'Designation', 'Employment Type', 'Joining Date', 'Probation End', 'Status']);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r->employee_id ?? '',
                    $r->user?->name ?? '',
                    $r->department?->name ?? '',
                    $r->jobTitle?->name ?? '',
                    $r->employmentType?->name ?? '',
                    $r->joining_date?->toDateString() ?? '',
                    $r->probation_end_date?->toDateString() ?? '',
                    ucfirst(str_replace('_', ' ', $r->status ?? '')),
                ]);
            }
            fclose($handle);
        }, 'employee-lifecycle-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }
}
