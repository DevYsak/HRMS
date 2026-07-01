<?php

namespace App\Exports;

use App\Models\Employee;
use App\Services\EmployeeImportService;
use Illuminate\Support\Carbon;

/**
 * Existing employees in the import layout, so admins can round-trip
 * (export → edit → re-import in update mode). Rendered by SpreadsheetService.
 */
class EmployeesExport
{
    /** @return array<int, string> */
    public function headings(): array
    {
        return app(EmployeeImportService::class)->templateHeadings();
    }

    /** @return array<int, array<int, mixed>> */
    public function rows(): array
    {
        return Employee::with(['user', 'department', 'jobTitle', 'shift', 'manager', 'employmentType', 'payrollSettings'])
            ->get()
            ->map(function (Employee $e): array {
                $user = $e->user;
                $pay = $e->payrollSettings;
                $parts = explode(' ', trim((string) ($user?->name ?? '')), 2);
                $status = is_object($e->status) ? $e->status->value : $e->status;

                return [
                    $e->employee_id,
                    $parts[0] ?? '',
                    '',
                    $parts[1] ?? '',
                    $user?->email,
                    $e->gender,
                    $e->date_of_birth ? Carbon::parse($e->date_of_birth)->toDateString() : '',
                    $e->phone,
                    $e->emergency_contact,
                    $e->address,
                    $e->department?->name,
                    $e->jobTitle?->name,
                    is_object($user?->role) ? $user->role->value : $user?->role,
                    $e->manager?->email,
                    $e->joining_date ? Carbon::parse($e->joining_date)->toDateString() : '',
                    $e->employmentType?->name,
                    $e->shift?->name,
                    $status,
                    $e->employee_code,
                    $pay?->ctc,
                    $pay?->pf_number,
                    $pay?->esi_number,
                    $pay?->pan_number,
                    $pay?->aadhar_number,
                    $pay?->bank_name,
                    $pay?->account_number,
                    $pay?->ifsc_code,
                    '',
                ];
            })
            ->all();
    }
}
