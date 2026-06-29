<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmployeeSyncResource;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/employees
 *
 * Master employee list for the Python attendance engine. Only employees with
 * an `employee_code` (the device PIN) are returned, since the engine can only
 * map punches by that code. Pass ?all=1 to include codeless employees too.
 */
class EmployeeSyncController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $employees = Employee::query()
            ->with(['user', 'department', 'jobTitle', 'manager', 'shift'])
            ->when(! $request->boolean('all'), fn ($query) => $query->whereNotNull('employee_code'))
            ->orderBy('employee_code')
            ->get();

        return EmployeeSyncResource::collection($employees);
    }
}
