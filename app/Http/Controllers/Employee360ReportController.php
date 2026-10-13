<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Audit\AuditService;
use App\Services\People\Employee360Access;
use App\Services\People\Employee360Service;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download Employee Report — the Employee 360° page as a PDF. Built from the
 * same Employee360Service output, so a section the viewer cannot see on the
 * page (warnings, pay, activity…) is never in the file either.
 */
class Employee360ReportController extends Controller
{
    public function __invoke(Request $request, Employee $employee, Employee360Access $access, Employee360Service $service): Response
    {
        $user = $request->user();
        abort_unless($access->allows($user, $employee), 403);

        [$from, $to] = Employee360Service::range($request->query('from'), $request->query('to'));
        $data = $service->build($user, $employee, $from, $to, ['leave_status' => 'overview']);

        AuditLog::record($employee, 'exported', null, ['report' => 'employee_360', 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            subjectEmployeeId: $employee->id,
            context: ['module' => AuditService::EXPORTS, 'category' => AuditService::EMPLOYEE, 'event' => 'EMPLOYEE_360_EXPORTED']);

        $filename = 'employee-360_'.Str::slug($data['identity']['code'] ?: (string) $employee->id).'_'.$from->format('Ymd').'-'.$to->format('Ymd').'.pdf';

        return Pdf::loadView('pdf.employee-360', [
            ...$data,
            'from' => $from,
            'to' => $to,
            'scopeLabel' => $access->scopeLabel($user),
            'generatedBy' => $user->name,
        ])->setPaper('a4', 'portrait')->download($filename);
    }
}
